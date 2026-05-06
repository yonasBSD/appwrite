<?php

namespace Appwrite\Platform\Modules\Insights\Http\Reports;

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
        return 'deleteReport';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/reports/:reportId')
            ->desc('Delete report')
            ->groups(['api', 'insights'])
            ->label('scope', 'reports.write')
            ->label('event', 'reports.[reportId].delete')
            ->label('resourceType', RESOURCE_TYPE_REPORTS)
            ->label('audits.event', 'report.delete')
            ->label('audits.resource', 'report/{request.reportId}')
            ->label('abuse-key', 'projectId:{projectId},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'insights',
                group: 'reports',
                name: 'deleteReport',
                description: <<<EOT
                Delete an analyzer report and all its child insights.
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
            ->param('reportId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Report ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $reportId,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Event $queueForEvents
    ) {
        $report = $dbForPlatform->getDocument('reports', $reportId);

        if ($report->isEmpty() || $report->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::REPORT_NOT_FOUND);
        }

        $childInsights = $dbForPlatform->find('insights', [
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::equal('reportInternalId', [$report->getSequence()]),
            Query::limit(APP_LIMIT_COUNT),
        ]);

        foreach ($childInsights as $insight) {
            // Cascade through CTAs first.
            $childCTAs = $dbForPlatform->find('insightCTAs', [
                Query::equal('insightInternalId', [$insight->getSequence()]),
                Query::limit(APP_LIMIT_COUNT),
            ]);
            foreach ($childCTAs as $cta) {
                $dbForPlatform->deleteDocument('insightCTAs', $cta->getId());
            }

            $dbForPlatform->deleteDocument('insights', $insight->getId());
        }

        if (!$dbForPlatform->deleteDocument('reports', $report->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove report from DB');
        }

        $queueForEvents
            ->setParam('reportId', $report->getId())
            ->setPayload($response->output($report, Response::MODEL_REPORT));

        $response->noContent();
    }
}
