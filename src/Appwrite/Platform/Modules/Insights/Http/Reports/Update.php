<?php

namespace Appwrite\Platform\Modules\Insights\Http\Reports;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateReport';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/reports/:reportId')
            ->desc('Update report')
            ->groups(['api', 'insights'])
            ->label('scope', 'reports.write')
            ->label('event', 'reports.[reportId].update')
            ->label('resourceType', RESOURCE_TYPE_REPORTS)
            ->label('audits.event', 'report.update')
            ->label('audits.resource', 'report/{response.$id}')
            ->label('abuse-key', 'projectId:{projectId},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'insights',
                group: 'reports',
                name: 'updateReport',
                description: <<<EOT
                Update an analyzer report. Pass only the attributes you want to change.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_REPORT,
                    ),
                ]
            ))
            ->param('reportId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Report ID.', false, ['dbForPlatform'])
            ->param('title', null, new Nullable(new Text(256)), 'Short, human-readable title.', true)
            ->param('summary', null, new Nullable(new Text(4096, 0)), 'Markdown summary describing the report.', true)
            ->param('categories', null, new Nullable(new ArrayList(new Text(64), 32)), 'Categories covered by the report.', true)
            ->param('analyzedAt', null, new Nullable(new DatetimeValidator()), 'Time the report was analyzed in ISO 8601 format.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $reportId,
        ?string $title,
        ?string $summary,
        ?array $categories,
        ?string $analyzedAt,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Event $queueForEvents
    ) {
        $report = $dbForPlatform->getDocument('reports', $reportId);

        if ($report->isEmpty() || $report->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::REPORT_NOT_FOUND);
        }

        $changes = [];

        if ($title !== null) {
            $changes['title'] = $title;
        }
        if ($summary !== null) {
            $changes['summary'] = $summary;
        }
        if ($categories !== null) {
            $changes['categories'] = $categories;
        }
        if ($analyzedAt !== null) {
            $changes['analyzedAt'] = $analyzedAt;
        }

        if ($changes !== []) {
            foreach ($changes as $key => $value) {
                $report->setAttribute($key, $value);
            }
            $report = $dbForPlatform->updateDocument('reports', $report->getId(), $report);
        }

        $queueForEvents->setParam('reportId', $report->getId());

        $response->dynamic($report, Response::MODEL_REPORT);
    }
}
