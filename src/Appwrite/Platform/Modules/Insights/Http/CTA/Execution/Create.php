<?php

namespace Appwrite\Platform\Modules\Insights\Http\CTA\Execution;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Insights\CTA\Action as CTAAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Registry\Registry as UtopiaRegistry;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createInsightCTAExecution';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/insights/:insightId/ctas/:ctaId/executions')
            ->desc('Create insight CTA execution')
            ->groups(['api', 'insights'])
            ->label('scope', 'insights.write')
            ->label('event', 'insights.[insightId].ctas.[ctaId].executions.create')
            ->label('resourceType', RESOURCE_TYPE_INSIGHTS)
            ->label('audits.event', 'insight.cta.execution.create')
            ->label('audits.resource', 'insight/{request.insightId}')
            ->label('abuse-key', 'projectId:{projectId},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'insights',
                group: 'insights',
                name: 'createCTAExecution',
                description: <<<EOT
                Execute a CTA attached to an insight. Looks up the registered server-side action by name, validates the CTA params, and runs the action on behalf of the caller.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_INSIGHT_CTA_EXECUTION,
                    ),
                ]
            ))
            ->param('insightId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Insight ID.', false, ['dbForProject'])
            ->param('ctaId', '', new Text(64), 'CTA ID, unique within the parent insight.')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('insightCTARegistry')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $insightId,
        string $ctaId,
        Response $response,
        Document $project,
        Database $dbForProject,
        callable $getDatabasesDB,
        UtopiaRegistry $insightCTARegistry,
        EventDatabase $queueForDatabase,
        Event $queueForEvents,
        Authorization $authorization
    ) {
        $insight = $dbForProject->getDocument('insights', $insightId);

        if ($insight->isEmpty()) {
            throw new Exception(Exception::INSIGHT_NOT_FOUND);
        }

        $cta = null;
        foreach ($insight->getAttribute('ctas', []) as $candidate) {
            if (($candidate['id'] ?? null) === $ctaId) {
                $cta = $candidate;
                break;
            }
        }

        if ($cta === null) {
            throw new Exception(Exception::INSIGHT_CTA_NOT_FOUND);
        }

        $actionName = (string) ($cta['action'] ?? '');
        $params = $cta['params'] ?? [];

        if (\is_object($params)) {
            $params = (array) $params;
        }

        if (!\is_array($params)) {
            $params = [];
        }

        try {
            $action = $insightCTARegistry->get($actionName);
        } catch (\Throwable) {
            throw new Exception(Exception::INSIGHT_CTA_NOT_FOUND);
        }

        if (!$action instanceof CTAAction) {
            throw new Exception(Exception::INSIGHT_CTA_NOT_FOUND);
        }

        $paramsValidator = $action->getParams()['params']['validator'] ?? null;

        if ($paramsValidator !== null && !$paramsValidator->isValid($params)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $paramsValidator->getDescription());
        }

        $status = 'succeeded';
        $resultPayload = new \stdClass();

        $callback = $action->getCallback();

        if (!\is_callable($callback)) {
            throw new Exception(Exception::INSIGHT_CTA_NOT_FOUND);
        }

        try {
            $result = $callback(
                $params,
                $insight,
                $project,
                $dbForProject,
                $getDatabasesDB,
                $queueForDatabase,
                $queueForEvents,
                $authorization
            );
            $resultPayload = $result instanceof Document ? $result->getArrayCopy() : (array) $result;
        } catch (Exception $e) {
            if ($e->getType() === Exception::GENERAL_NOT_IMPLEMENTED) {
                throw $e;
            }
            $status = 'failed';
            $resultPayload = ['error' => $e->getMessage()];
        }

        $queueForEvents
            ->setParam('insightId', $insight->getId())
            ->setParam('ctaId', $ctaId);

        $response->dynamic(new Document([
            'insightId' => $insight->getId(),
            'ctaId' => $ctaId,
            'action' => $actionName,
            'status' => $status,
            'result' => $resultPayload,
        ]), Response::MODEL_INSIGHT_CTA_EXECUTION);
    }
}
