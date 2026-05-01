<?php

namespace Appwrite\Platform\Modules\Insights\Http\Cta;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Insights\Cta\Registry as InsightCtaRegistry;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Trigger extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'triggerInsightCta';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/insights/:insightId/ctas/:ctaId/trigger')
            ->desc('Trigger insight CTA')
            ->groups(['api', 'insights'])
            ->label('scope', 'insights.write')
            ->label('event', 'insights.[insightId].ctas.[ctaId].trigger')
            ->label('resourceType', RESOURCE_TYPE_INSIGHTS)
            ->label('audits.event', 'insight.cta.trigger')
            ->label('audits.resource', 'insight/{request.insightId}')
            ->label('abuse-key', 'projectId:{projectId},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'insights',
                group: 'insights',
                name: 'triggerCta',
                description: <<<EOT
                Trigger a CTA attached to an insight. Looks up the registered server-side action by name, validates the CTA params, and executes the action on behalf of the caller.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_INSIGHT_CTA_RESULT,
                    ),
                ]
            ))
            ->param('insightId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Insight ID.', false, ['dbForProject'])
            ->param('ctaId', '', new Text(64), 'CTA ID, unique within the parent insight.')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('insightCtaRegistry')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $insightId,
        string $ctaId,
        Response $response,
        Document $project,
        Database $dbForProject,
        InsightCtaRegistry $insightCtaRegistry,
        Event $queueForEvents
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
        if (!\is_array($params)) {
            $params = [];
        }

        $action = $insightCtaRegistry->get($actionName);
        $action->validate($params);

        $status = 'succeeded';
        $resultPayload = new \stdClass();

        try {
            $result = $action->execute($params, $insight, $project, $dbForProject);
            $resultPayload = $result->getArrayCopy();
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
        ]), Response::MODEL_INSIGHT_CTA_RESULT);
    }
}
