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
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Nullable;
use Utopia\Validator\WhiteList;

/**
 * User-facing Update endpoint.
 *
 * Limited to user-controlled state: dismissal (status), and severity overrides.
 * Analyzer-controlled fields (title, summary, ctas, analyzedAt) flow
 * through the manager-only Create endpoint — analyzers re-ingest by deleting
 * the stale insight and submitting a fresh one.
 */
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
            ->setHttpPath('/v1/reports/:reportId/insights/:insightId')
            ->desc('Update insight')
            ->groups(['api', 'insights'])
            ->label('scope', 'insights.write')
            ->label('event', 'reports.[reportId].insights.[insightId].update')
            ->label('resourceType', RESOURCE_TYPE_INSIGHTS)
            ->label('audits.event', 'insight.update')
            ->label('audits.resource', 'report/{request.reportId}/insight/{response.$id}')
            ->label('abuse-key', 'projectId:{projectId},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'insights',
                group: 'insights',
                name: 'update',
                description: <<<EOT
                Update user-controlled state on an insight. Set `status` to `dismissed` to dismiss it (the dismissal timestamp and user are recorded automatically) or back to `active` to undo a dismissal. `severity` lets users escalate or downgrade the analyzer's classification.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_INSIGHT,
                    ),
                ]
            ))
            ->param('reportId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Parent report ID.', false, ['dbForPlatform'])
            ->param('insightId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Insight ID.', false, ['dbForPlatform'])
            ->param('severity', null, new Nullable(new WhiteList(INSIGHT_SEVERITIES, true)), 'Insight severity. One of `info`, `warning`, `critical`.', true)
            ->param('status', null, new Nullable(new WhiteList(INSIGHT_STATUSES, true)), 'Insight status. Set to `dismissed` to dismiss the insight, `active` to undo a dismissal.', true)
            ->inject('response')
            ->inject('user')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $reportId,
        string $insightId,
        ?string $severity,
        ?string $status,
        Response $response,
        Document $user,
        Document $project,
        Database $dbForPlatform,
        Event $queueForEvents
    ) {
        $report = $dbForPlatform->getDocument('reports', $reportId);

        if ($report->isEmpty() || $report->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::REPORT_NOT_FOUND);
        }

        $insight = $dbForPlatform->getDocument('insights', $insightId);

        if (
            $insight->isEmpty()
            || $insight->getAttribute('projectInternalId') !== $project->getSequence()
            || $insight->getAttribute('reportInternalId') !== $report->getSequence()
        ) {
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

        if ($changes !== []) {
            foreach ($changes as $key => $value) {
                $insight->setAttribute($key, $value);
            }
            $insight = $dbForPlatform->updateDocument('insights', $insight->getId(), $insight);
        }

        $queueForEvents
            ->setParam('reportId', $report->getId())
            ->setParam('insightId', $insight->getId());

        $response->dynamic($insight, Response::MODEL_INSIGHT);
    }
}
