<?php

namespace Appwrite\Platform\Modules\Advisor\Http\Insights;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getInsight';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reports/:reportId/insights/:insightId')
            ->desc('Get insight')
            ->groups(['api', 'advisor'])
            ->label('scope', 'insights.read')
            ->label('resourceType', RESOURCE_TYPE_INSIGHTS)
            ->label('sdk', new Method(
                namespace: 'advisor',
                group: 'insights',
                name: 'getInsight',
                description: '/docs/references/advisor/get-insight.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_INSIGHT,
                    ),
                ]
            ))
            ->param('reportId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Parent report ID.', false, ['dbForPlatform'])
            ->param('insightId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Insight ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $reportId,
        string $insightId,
        Response $response,
        Document $project,
        Database $dbForPlatform
    ) {
        $report = $dbForPlatform->getDocument('reports', $reportId);

        if ($report->isEmpty() || $report->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::REPORT_NOT_FOUND);
        }

        $insight = $this->getInsightFromReport($report, $insightId, $dbForPlatform);

        if ($insight === null) {
            throw new Exception(Exception::INSIGHT_NOT_FOUND);
        }

        $response->dynamic($insight, Response::MODEL_INSIGHT);
    }

    /**
     * Resolve a nested insight scoped to its parent report.
     *
     * Fast path: pull from the report's embedded subquery payload (capped at APP_LIMIT_SUBQUERY).
     * Fall back to a direct lookup so insights past the cap remain reachable.
     */
    private function getInsightFromReport(Document $report, string $insightId, Database $dbForPlatform): ?Document
    {
        $embedded = $report->find('$id', $insightId, 'insights');

        if (!empty($embedded)) {
            return $embedded instanceof Document ? $embedded : new Document($embedded);
        }

        $insight = $dbForPlatform->getDocument('insights', $insightId);

        if (
            $insight->isEmpty()
            || $insight->getAttribute('projectInternalId') !== $report->getAttribute('projectInternalId')
            || $insight->getAttribute('reportInternalId') !== $report->getSequence()
        ) {
            return null;
        }

        return $insight;
    }
}
