<?php

namespace Appwrite\Platform\Modules\Insights\Http\Manager\Insights;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Insights\Validator\CTAs as CTAsValidator;
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
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

/**
 * Manager-only endpoint for analyzer ingestion.
 *
 * Insights are produced by internal Appwrite services (edge, executor,
 * background analyzers) — never by user clients. The endpoint lives under
 * /v1/manager/* and is hidden from generated SDKs to keep that contract
 * explicit. Internal services call it directly over HTTP using a server
 * API key with the `insights.manager` scope.
 */
class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createInsight';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/manager/insights')
            ->desc('Create insight')
            ->groups(['api', 'manager', 'insights'])
            ->label('scope', 'insights.manager')
            ->label('event', 'insights.[insightId].create')
            ->label('resourceType', RESOURCE_TYPE_INSIGHTS)
            ->label('audits.event', 'insight.create')
            ->label('audits.resource', 'insight/{response.$id}')
            ->label('abuse-key', 'projectId:{projectId},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'manager',
                group: 'insights',
                name: 'createInsight',
                description: <<<EOT
                Manager-only: ingest an insight produced by an internal analyzer (edge, executor, background worker, …). Not exposed to user-facing client or server SDKs.
                EOT,
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_INSIGHT,
                    ),
                ],
                hide: true,
            ))
            ->param('insightId', '', fn (Database $dbForPlatform) => new CustomId(false, $dbForPlatform->getAdapter()->getMaxUIDLength()), 'Insight ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForPlatform'])
            ->param('reportId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Parent report ID. Optional — leave empty for ad-hoc insights not attached to a report.', true, ['dbForPlatform'])
            ->param('type', '', new WhiteList(INSIGHT_TYPES, true), 'Insight type. Determines the analyzer that owns this insight and the shape of `payload`.')
            ->param('severity', INSIGHT_SEVERITY_INFO, new WhiteList(INSIGHT_SEVERITIES, true), 'Insight severity. One of `info`, `warning`, `critical`.', true)
            ->param('resourceType', '', new Text(64), 'Plural resource type the insight is about, e.g. `databases`, `sites`, `functions`.')
            ->param('resourceId', '', new Text(36), 'ID of the resource the insight is about.')
            ->param('resourceInternalId', '', new Text(36), 'Internal ID of the resource the insight is about.', true)
            ->param('parentResourceType', '', new Text(64), 'Plural noun for the parent (containing) resource, e.g. `tables` for an insight about a column index. Optional.', true)
            ->param('parentResourceId', '', new Text(36), 'ID of the parent resource.', true)
            ->param('parentResourceInternalId', '', new Text(36), 'Internal ID of the parent resource.', true)
            ->param('title', '', new Text(256), 'Short, human-readable title.')
            ->param('summary', '', new Text(4096, 0), 'Markdown summary describing the insight.', true)
            ->param('payload', null, new Nullable(new JSON()), 'Type-specific structured payload.', true)
            ->param('ctas', [], new CTAsValidator(), 'Array of call-to-action descriptors. Each must contain `key` (unique within the insight), `label`, `service`, `method`, and an optional `params` object.', true)
            ->param('analyzedAt', null, new Nullable(new DatetimeValidator()), 'Time the insight was analyzed in ISO 8601 format. Defaults to now.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $insightId,
        string $reportId,
        string $type,
        string $severity,
        string $resourceType,
        string $resourceId,
        string $resourceInternalId,
        string $parentResourceType,
        string $parentResourceId,
        string $parentResourceInternalId,
        string $title,
        string $summary,
        ?array $payload,
        array $ctas,
        ?string $analyzedAt,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Event $queueForEvents
    ) {
        $insightId = ($insightId === 'unique()') ? ID::unique() : $insightId;

        $reportInternalId = '';

        if ($reportId !== '') {
            $report = $dbForPlatform->getDocument('reports', $reportId);

            if ($report->isEmpty() || $report->getAttribute('projectInternalId') !== $project->getSequence()) {
                throw new Exception(Exception::REPORT_NOT_FOUND);
            }

            $reportInternalId = $report->getSequence();
        }

        $seen = [];
        $normalizedCTAs = [];

        foreach ($ctas as $cta) {
            $key = (string) $cta['key'];
            if (isset($seen[$key])) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'CTA `key` values must be unique within an insight.');
            }
            $seen[$key] = true;

            $normalizedCTAs[] = [
                'key' => $key,
                'label' => (string) $cta['label'],
                'service' => (string) $cta['service'],
                'method' => (string) $cta['method'],
                'params' => $cta['params'] ?? new \stdClass(),
            ];
        }

        try {
            $insight = $dbForPlatform->createDocument('insights', new Document([
                '$id' => $insightId,
                'projectInternalId' => $project->getSequence(),
                'projectId' => $project->getId(),
                'reportInternalId' => $reportInternalId,
                'reportId' => $reportId,
                'type' => $type,
                'severity' => $severity,
                'status' => INSIGHT_STATUS_ACTIVE,
                'resourceType' => $resourceType,
                'resourceId' => $resourceId,
                'resourceInternalId' => $resourceInternalId,
                'parentResourceType' => $parentResourceType,
                'parentResourceId' => $parentResourceId,
                'parentResourceInternalId' => $parentResourceInternalId,
                'title' => $title,
                'summary' => $summary,
                'payload' => $payload,
                'analyzedAt' => $analyzedAt,
                'dismissedAt' => null,
                'dismissedBy' => '',
            ]));
        } catch (DuplicateException) {
            throw new Exception(Exception::INSIGHT_ALREADY_EXISTS);
        }

        foreach ($normalizedCTAs as $cta) {
            $dbForPlatform->createDocument('insightCTAs', new Document([
                '$id' => ID::unique(),
                'projectInternalId' => $project->getSequence(),
                'projectId' => $project->getId(),
                'insightInternalId' => $insight->getSequence(),
                'insightId' => $insight->getId(),
                'key' => $cta['key'],
                'label' => $cta['label'],
                'service' => $cta['service'],
                'method' => $cta['method'],
                'params' => $cta['params'],
            ]));
        }

        // Re-fetch so the subQueryInsightCTAs filter embeds the freshly-created
        // CTA documents on the response — keeps a single round-trip for callers.
        $insight = $dbForPlatform->getDocument('insights', $insight->getId());

        $queueForEvents->setParam('insightId', $insight->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($insight, Response::MODEL_INSIGHT);
    }
}
