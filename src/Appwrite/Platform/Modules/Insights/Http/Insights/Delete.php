<?php

namespace Appwrite\Platform\Modules\Insights\Http\Insights;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteInsight';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/reports/:reportId/insights/:insightId')
            ->desc('Delete insight')
            ->groups(['api', 'insights'])
            ->label('scope', 'insights.write')
            ->label('event', 'reports.[reportId].insights.[insightId].delete')
            ->label('resourceType', RESOURCE_TYPE_INSIGHTS)
            ->label('audits.event', 'insight.delete')
            ->label('audits.resource', 'report/{request.reportId}/insight/{request.insightId}')
            ->label('abuse-key', 'projectId:{projectId},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'insights',
                group: 'insights',
                name: 'delete',
                description: <<<EOT
                Delete an insight by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::NONE
            ))
            ->param('reportId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Parent report ID.', false, ['dbForPlatform'])
            ->param('insightId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Insight ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $reportId,
        string $insightId,
        Response $response,
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

        // Cascade delete child CTAs first.
        $childCTAs = $dbForPlatform->find('insightCTAs', [
            Query::equal('insightInternalId', [$insight->getSequence()]),
            Query::limit(APP_LIMIT_COUNT),
        ]);

        foreach ($childCTAs as $cta) {
            $dbForPlatform->deleteDocument('insightCTAs', $cta->getId());
        }

        if (!$dbForPlatform->deleteDocument('insights', $insight->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove insight from DB');
        }

        $queueForEvents
            ->setParam('reportId', $report->getId())
            ->setParam('insightId', $insight->getId())
            ->setPayload($response->output($insight, Response::MODEL_INSIGHT));

        $response->noContent();
    }
}
