<?php

namespace Appwrite\Platform\Modules\Insights\Http\Insights;

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
            ->setHttpPath('/v1/insights')
            ->desc('Create insight')
            ->groups(['api', 'insights'])
            ->label('scope', 'insights.write')
            ->label('event', 'insights.[insightId].create')
            ->label('resourceType', RESOURCE_TYPE_INSIGHTS)
            ->label('audits.event', 'insight.create')
            ->label('audits.resource', 'insight/{response.$id}')
            ->label('abuse-key', 'projectId:{projectId},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'insights',
                group: 'insights',
                name: 'create',
                description: <<<EOT
                Create a new insight. Server-side only: insights are produced by analyzers and surfaced to project members.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_INSIGHT,
                    ),
                ]
            ))
            ->param('insightId', '', fn (Database $dbForPlatform) => new CustomId(false, $dbForPlatform->getAdapter()->getMaxUIDLength()), 'Insight ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForPlatform'])
            ->param('reportId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Parent report ID. Optional — leave empty for ad-hoc insights not attached to a report.', true, ['dbForPlatform'])
            ->param('type', '', new WhiteList(INSIGHT_TYPES, true), 'Insight type. Determines the analyzer that owns this insight and the shape of `payload`.')
            ->param('severity', INSIGHT_SEVERITY_INFO, new WhiteList(INSIGHT_SEVERITIES, true), 'Insight severity. One of `info`, `warning`, `critical`.', true)
            ->param('resourceType', '', new Text(64), 'Plural resource type the insight is about, e.g. `databases`, `sites`, `functions`.')
            ->param('resourceId', '', new Text(36), 'ID of the resource the insight is about.')
            ->param('resourceInternalId', '', new Text(36), 'Internal ID of the resource the insight is about.', true)
            ->param('title', '', new Text(256), 'Short, human-readable title.')
            ->param('summary', '', new Text(4096, 0), 'Markdown summary describing the insight.', true)
            ->param('payload', null, new Nullable(new JSON()), 'Type-specific structured payload.', true)
            ->param('ctas', [], new CTAsValidator(), 'Array of call-to-action descriptors. Each must contain `id`, `label`, `action`, and optional `params`.', true)
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
            $ctaId = (string) $cta['id'];
            if (isset($seen[$ctaId])) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'CTA `id` values must be unique within an insight.');
            }
            $seen[$ctaId] = true;

            $normalizedCTAs[] = [
                'id' => $ctaId,
                'label' => (string) $cta['label'],
                'action' => (string) $cta['action'],
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
                'title' => $title,
                'summary' => $summary,
                'payload' => $payload,
                'ctas' => $normalizedCTAs,
                'analyzedAt' => $analyzedAt,
                'dismissedAt' => null,
                'dismissedBy' => '',
            ]));
        } catch (DuplicateException) {
            throw new Exception(Exception::INSIGHT_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('insightId', $insight->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($insight, Response::MODEL_INSIGHT);
    }
}
