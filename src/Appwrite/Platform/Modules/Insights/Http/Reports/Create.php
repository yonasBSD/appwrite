<?php

namespace Appwrite\Platform\Modules\Insights\Http\Reports;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createReport';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/reports')
            ->desc('Create report')
            ->groups(['api', 'insights'])
            ->label('scope', 'reports.write')
            ->label('event', 'reports.[reportId].create')
            ->label('resourceType', RESOURCE_TYPE_REPORTS)
            ->label('audits.event', 'report.create')
            ->label('audits.resource', 'report/{response.$id}')
            ->label('abuse-key', 'projectId:{projectId},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'insights',
                group: 'reports',
                name: 'createReport',
                description: <<<EOT
                Create a new analyzer report. A report groups one or more insights produced by a single analyzer run (e.g. a Lighthouse audit of a URL, a database analyzer pass over a project).
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_REPORT,
                    ),
                ]
            ))
            ->param('reportId', '', fn (Database $dbForPlatform) => new CustomId(false, $dbForPlatform->getAdapter()->getMaxUIDLength()), 'Report ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForPlatform'])
            ->param('type', '', new WhiteList(REPORT_TYPES, true), 'Analyzer type. One of `lighthouse`, `audit`, `databaseAnalyzer`.')
            ->param('title', '', new Text(256), 'Short, human-readable title.')
            ->param('summary', '', new Text(4096, 0), 'Markdown summary describing the report.', true)
            ->param('targetType', '', new Text(64), 'Plural noun describing what the report analyzes, e.g. `databases`, `sites`, `urls`.')
            ->param('target', '', new Text(2048), 'Free-form target identifier (URL for lighthouse, resource ID for db).')
            ->param('categories', [], new ArrayList(new Text(64), 32), 'Categories covered by the report, e.g. `performance`, `accessibility`. Max 32 entries, each 64 chars.', true)
            ->param('analyzedAt', null, new Nullable(new DatetimeValidator()), 'Time the report was analyzed in ISO 8601 format. Defaults to now.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $reportId,
        string $type,
        string $title,
        string $summary,
        string $targetType,
        string $target,
        array $categories,
        ?string $analyzedAt,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Event $queueForEvents
    ) {
        $reportId = ($reportId === 'unique()') ? ID::unique() : $reportId;

        try {
            $report = $dbForPlatform->createDocument('reports', new Document([
                '$id' => $reportId,
                'projectInternalId' => $project->getSequence(),
                'projectId' => $project->getId(),
                'type' => $type,
                'title' => $title,
                'summary' => $summary,
                'targetType' => $targetType,
                'target' => $target,
                'categories' => $categories,
                'analyzedAt' => $analyzedAt,
            ]));
        } catch (DuplicateException) {
            throw new Exception(Exception::REPORT_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('reportId', $report->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($report, Response::MODEL_REPORT);
    }
}
