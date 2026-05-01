<?php

namespace Appwrite\Platform\Modules\Insights\Http\Insights;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateInsight';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/insights/:insightId')
            ->desc('Update insight')
            ->groups(['api', 'insights'])
            ->label('scope', 'insights.write')
            ->label('event', 'insights.[insightId].update')
            ->label('resourceType', RESOURCE_TYPE_INSIGHTS)
            ->label('audits.event', 'insight.update')
            ->label('audits.resource', 'insight/{response.$id}')
            ->label('abuse-key', 'projectId:{projectId},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'insights',
                group: 'insights',
                name: 'update',
                description: <<<EOT
                Update an insight. Pass only the attributes you want to change.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_INSIGHT,
                    ),
                ]
            ))
            ->param('insightId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Insight ID.', false, ['dbForProject'])
            ->param('severity', null, new Nullable(new WhiteList(INSIGHT_SEVERITIES, true)), 'Insight severity. One of `info`, `warning`, `critical`.', true)
            ->param('status', null, new Nullable(new WhiteList(INSIGHT_STATUSES, true)), 'Insight status. Set to `dismissed` to dismiss the insight, `active` to undo a dismissal.', true)
            ->param('title', null, new Nullable(new Text(256)), 'Short, human-readable title.', true)
            ->param('summary', null, new Nullable(new Text(4096, 0)), 'Markdown summary describing the insight.', true)
            ->param('payload', null, new Nullable(new JSON()), 'Type-specific structured payload.', true)
            ->param('ctas', null, new Nullable(new ArrayList(new JSON(), 16)), 'Array of call-to-action descriptors.', true)
            ->param('analyzedAt', null, new Nullable(new DatetimeValidator()), 'Time the insight was analyzed in ISO 8601 format.', true)
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $insightId,
        ?string $severity,
        ?string $status,
        ?string $title,
        ?string $summary,
        ?array $payload,
        ?array $ctas,
        ?string $analyzedAt,
        Response $response,
        Document $user,
        Database $dbForProject,
        Event $queueForEvents
    ) {
        $insight = $dbForProject->getDocument('insights', $insightId);

        if ($insight->isEmpty()) {
            throw new Exception(Exception::INSIGHT_NOT_FOUND);
        }

        $changes = [];

        if ($severity !== null) {
            $changes['severity'] = $severity;
        }
        if ($status !== null && $status !== $insight->getAttribute('status')) {
            $changes['status'] = $status;
            if ($status === INSIGHT_STATUS_DISMISSED) {
                $changes['dismissedAt'] = DateTime::now();
                $changes['dismissedBy'] = $user->getId();
            } else {
                $changes['dismissedAt'] = null;
                $changes['dismissedBy'] = '';
            }
        }
        if ($title !== null) {
            $changes['title'] = $title;
        }
        if ($summary !== null) {
            $changes['summary'] = $summary;
        }
        if ($payload !== null) {
            $changes['payload'] = $payload;
        }
        if ($ctas !== null) {
            $normalized = [];
            foreach ($ctas as $cta) {
                if (!isset($cta['id'], $cta['label'], $cta['action'])) {
                    throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Each CTA must define `id`, `label`, and `action`.');
                }
                $normalized[] = [
                    'id' => (string) $cta['id'],
                    'label' => (string) $cta['label'],
                    'action' => (string) $cta['action'],
                    'params' => $cta['params'] ?? new \stdClass(),
                ];
            }
            $changes['ctas'] = $normalized;
        }
        if ($analyzedAt !== null) {
            $changes['analyzedAt'] = $analyzedAt;
        }

        if ($changes !== []) {
            $insight = $dbForProject->updateDocument('insights', $insight->getId(), new Document($changes));
        }

        $queueForEvents->setParam('insightId', $insight->getId());

        $response->dynamic($insight, Response::MODEL_INSIGHT);
    }
}
