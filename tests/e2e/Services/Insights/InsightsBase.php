<?php

namespace Tests\E2E\Services\Insights;

use PHPUnit\Framework\Attributes\Depends;
use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;

trait InsightsBase
{
    protected function serverHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }

    protected function clientHeaders(): array
    {
        return array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    protected function createReport(array $body, ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_POST, '/reports', $headers ?? $this->serverHeaders(), $body);
    }

    protected function getReport(string $reportId, ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_GET, '/reports/' . $reportId, $headers ?? $this->serverHeaders());
    }

    protected function listReports(array $params = [], ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_GET, '/reports', $headers ?? $this->serverHeaders(), $params);
    }

    protected function updateReport(string $reportId, array $body, ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/reports/' . $reportId, $headers ?? $this->serverHeaders(), $body);
    }

    protected function deleteReport(string $reportId, ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_DELETE, '/reports/' . $reportId, $headers ?? $this->serverHeaders());
    }

    protected function createInsight(string $reportId, array $body, ?array $headers = null): array
    {
        // Manager-only endpoint — internal Appwrite services ingest here, not user SDKs.
        return $this->client->call(Client::METHOD_POST, '/manager/reports/' . $reportId . '/insights', $headers ?? $this->serverHeaders(), $body);
    }

    protected function getInsight(string $reportId, string $insightId, ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_GET, '/reports/' . $reportId . '/insights/' . $insightId, $headers ?? $this->serverHeaders());
    }

    protected function listInsights(string $reportId, array $params = [], ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_GET, '/reports/' . $reportId . '/insights', $headers ?? $this->serverHeaders(), $params);
    }

    protected function updateInsight(string $reportId, string $insightId, array $body, ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/reports/' . $reportId . '/insights/' . $insightId, $headers ?? $this->serverHeaders(), $body);
    }

    protected function deleteInsight(string $reportId, string $insightId, ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_DELETE, '/reports/' . $reportId . '/insights/' . $insightId, $headers ?? $this->serverHeaders());
    }

    /**
     * Create a throwaway report so a standalone validation test has a parent
     * report to nest under. Caller is responsible for `deleteReport()`.
     */
    protected function createFixtureReport(string $type = 'audit'): string
    {
        $reportId = ID::unique();
        $report = $this->createReport([
            'reportId' => $reportId,
            'type' => $type,
            'title' => 'Fixture report',
            'targetType' => 'sites',
            'target' => 'fixture',
        ]);
        $this->assertSame(201, $report['headers']['status-code']);
        return $reportId;
    }

    /**
     * Sample CTA pointing at the engine-specific public API.
     *
     * The `engine` parameter selects which API the CTA targets:
     *  - `databases`    → service `databases`,    method `createIndex` (legacy, params use collectionId/attributes)
     *  - `tablesDB`     → service `tablesDB`,     method `createIndex` (params use tableId/columns)
     *  - `documentsDB`  → service `documentsDB`,  method `createIndex` (params use collectionId/attributes)
     *  - `vectorsDB`    → service `vectorsDB`,    method `createIndex` (params use collectionId/attributes)
     */
    protected function sampleCTA(string $engine = 'tablesDB'): array
    {
        $base = [
            'label' => 'Create missing index',
            'method' => 'createIndex',
        ];

        return match ($engine) {
            'databases' => $base + [
                'service' => 'databases',
                'params' => [
                    'databaseId' => 'main',
                    'collectionId' => 'orders',
                    'key' => '_idx_status',
                    'type' => 'key',
                    'attributes' => ['status'],
                ],
            ],
            'tablesDB' => $base + [
                'service' => 'tablesDB',
                'params' => [
                    'databaseId' => 'main',
                    'tableId' => 'orders',
                    'key' => '_idx_status',
                    'type' => 'key',
                    'columns' => ['status'],
                ],
            ],
            'documentsDB' => $base + [
                'service' => 'documentsDB',
                'params' => [
                    'databaseId' => 'main',
                    'collectionId' => 'orders',
                    'key' => '_idx_status',
                    'type' => 'key',
                    'attributes' => ['status'],
                ],
            ],
            'vectorsDB' => $base + [
                'service' => 'vectorsDB',
                'params' => [
                    'databaseId' => 'main',
                    'collectionId' => 'orders',
                    'key' => '_idx_status',
                    'type' => 'key',
                    'attributes' => ['status'],
                ],
            ],
            default => throw new \InvalidArgumentException("Unknown engine: {$engine}"),
        };
    }

    protected function sampleInsight(?string $insightId = null, string $engine = 'tablesDB'): array
    {
        $type = match ($engine) {
            'databases' => 'databaseIndex',
            'tablesDB' => 'tablesDBIndex',
            'documentsDB' => 'documentsDBIndex',
            'vectorsDB' => 'vectorsDBIndex',
            default => throw new \InvalidArgumentException("Unknown engine: {$engine}"),
        };

        $parentResourceType = match ($engine) {
            'tablesDB' => 'tables',
            'databases', 'documentsDB', 'vectorsDB' => 'collections',
        };

        return [
            'insightId' => $insightId ?? ID::unique(),
            'type' => $type,
            'severity' => 'warning',
            'resourceType' => 'indexes',
            'resourceId' => '_idx_status',
            'parentResourceType' => $parentResourceType,
            'parentResourceId' => 'orders',
            'title' => 'Missing index on collection orders',
            'summary' => 'Queries against `orders.status` are scanning the full collection.',
            'ctas' => [$this->sampleCTA($engine)],
        ];
    }

    public function testCreateReport(): array
    {
        $reportId = ID::unique();

        $report = $this->createReport([
            'reportId' => $reportId,
            'type' => 'databaseAnalyzer',
            'title' => 'Database analyzer report',
            'summary' => 'Daily scan of project DB.',
            'targetType' => 'databases',
            'target' => 'main',
            'categories' => ['performance', 'integrity'],
        ]);

        $this->assertSame(201, $report['headers']['status-code']);
        $this->assertSame($reportId, $report['body']['$id']);
        $this->assertSame('databaseAnalyzer', $report['body']['type']);
        $this->assertSame('Database analyzer report', $report['body']['title']);
        $this->assertSame('main', $report['body']['target']);
        $this->assertSame('databases', $report['body']['targetType']);
        $this->assertSame(['performance', 'integrity'], $report['body']['categories']);
        $this->assertArrayHasKey('$createdAt', $report['body']);
        $this->assertArrayHasKey('$updatedAt', $report['body']);

        return ['reportId' => $reportId];
    }

    public function testCreateReportRejectsInvalidType(): void
    {
        $report = $this->createReport([
            'reportId' => ID::unique(),
            'type' => 'unknownAnalyzer',
            'title' => 'Bad type',
            'targetType' => 'databases',
            'target' => 'main',
        ]);

        $this->assertSame(400, $report['headers']['status-code']);
    }

    public function testCreateReportRejectsDuplicateId(): void
    {
        $reportId = ID::unique();

        $first = $this->createReport([
            'reportId' => $reportId,
            'type' => 'audit',
            'title' => 'First',
            'targetType' => 'sites',
            'target' => 'home',
        ]);
        $this->assertSame(201, $first['headers']['status-code']);

        $second = $this->createReport([
            'reportId' => $reportId,
            'type' => 'audit',
            'title' => 'Second',
            'targetType' => 'sites',
            'target' => 'home',
        ]);
        $this->assertSame(409, $second['headers']['status-code']);
        $this->assertSame('report_already_exists', $second['body']['type']);

        $this->deleteReport($reportId);
    }

    #[Depends('testCreateReport')]
    public function testGetReport(array $data): array
    {
        $report = $this->getReport($data['reportId']);

        $this->assertSame(200, $report['headers']['status-code']);
        $this->assertSame($data['reportId'], $report['body']['$id']);
        $this->assertSame('databaseAnalyzer', $report['body']['type']);

        $missing = $this->getReport('missing');
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('report_not_found', $missing['body']['type']);

        return $data;
    }

    #[Depends('testGetReport')]
    public function testListReports(array $data): array
    {
        $list = $this->listReports();

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        $this->assertNotEmpty($list['body']['reports']);

        $byType = $this->listReports([
            'queries' => [
                'equal("type", "databaseAnalyzer")',
            ],
        ]);
        $this->assertSame(200, $byType['headers']['status-code']);
        foreach ($byType['body']['reports'] as $report) {
            $this->assertSame('databaseAnalyzer', $report['type']);
        }

        $byTarget = $this->listReports([
            'queries' => [
                'equal("targetType", "databases")',
                'equal("target", "main")',
            ],
        ]);
        $this->assertSame(200, $byTarget['headers']['status-code']);
        foreach ($byTarget['body']['reports'] as $report) {
            $this->assertSame('databases', $report['targetType']);
            $this->assertSame('main', $report['target']);
        }

        return $data;
    }

    #[Depends('testListReports')]
    public function testUpdateReport(array $data): array
    {
        $original = $this->getReport($data['reportId']);
        $this->assertSame(200, $original['headers']['status-code']);

        $updated = $this->updateReport($data['reportId'], [
            'title' => 'Updated database analyzer report',
            'summary' => 'Updated summary.',
        ]);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('Updated database analyzer report', $updated['body']['title']);
        $this->assertSame('Updated summary.', $updated['body']['summary']);

        $this->assertSame($original['body']['type'], $updated['body']['type']);
        $this->assertSame($original['body']['target'], $updated['body']['target']);
        $this->assertSame($original['body']['targetType'], $updated['body']['targetType']);

        $missing = $this->updateReport('missing', ['title' => 'x']);
        $this->assertSame(404, $missing['headers']['status-code']);

        return $data;
    }

    #[Depends('testUpdateReport')]
    public function testCreate(array $data): array
    {
        $insightId = ID::unique();

        $insight = $this->createInsight($data['reportId'], $this->sampleInsight($insightId, 'tablesDB'));

        $this->assertSame(201, $insight['headers']['status-code']);
        $this->assertSame($insightId, $insight['body']['$id']);
        $this->assertSame($data['reportId'], $insight['body']['reportId']);
        $this->assertSame('tablesDBIndex', $insight['body']['type']);
        $this->assertSame('warning', $insight['body']['severity']);
        $this->assertSame('active', $insight['body']['status']);
        $this->assertSame('indexes', $insight['body']['resourceType']);
        $this->assertSame('_idx_status', $insight['body']['resourceId']);
        $this->assertSame('tables', $insight['body']['parentResourceType']);
        $this->assertSame('orders', $insight['body']['parentResourceId']);
        $this->assertSame('Missing index on collection orders', $insight['body']['title']);
        $this->assertCount(1, $insight['body']['ctas']);
        $this->assertSame($insightId, $insight['body']['ctas'][0]['insightId']);
        $this->assertSame('Create missing index', $insight['body']['ctas'][0]['label']);
        $this->assertSame('tablesDB', $insight['body']['ctas'][0]['service']);
        $this->assertSame('createIndex', $insight['body']['ctas'][0]['method']);
        $this->assertSame('orders', $insight['body']['ctas'][0]['params']['tableId']);
        $this->assertSame(['status'], $insight['body']['ctas'][0]['params']['columns']);
        $this->assertArrayHasKey('$id', $insight['body']['ctas'][0]);
        $this->assertArrayHasKey('$createdAt', $insight['body']['ctas'][0]);
        $this->assertEmpty($insight['body']['dismissedAt']);
        $this->assertEmpty($insight['body']['dismissedBy']);

        return $data + ['insightId' => $insightId];
    }

    /**
     * @dataProvider engineMatrixProvider
     */
    public function testCreateForEachEngine(string $engine, string $expectedType, string $expectedService, string $expectedMethod): void
    {
        $reportId = $this->createFixtureReport();
        $insightId = ID::unique();

        $insight = $this->createInsight($reportId, $this->sampleInsight($insightId, $engine));

        $this->assertSame(201, $insight['headers']['status-code']);
        $this->assertSame($expectedType, $insight['body']['type']);
        $this->assertSame($expectedService, $insight['body']['ctas'][0]['service']);
        $this->assertSame($expectedMethod, $insight['body']['ctas'][0]['method']);

        $this->deleteInsight($reportId, $insightId);
        $this->deleteReport($reportId);
    }

    public static function engineMatrixProvider(): array
    {
        return [
            'legacy databases' => ['databases', 'databaseIndex', 'databases', 'createIndex'],
            'tablesDB' => ['tablesDB', 'tablesDBIndex', 'tablesDB', 'createIndex'],
            'documentsDB' => ['documentsDB', 'documentsDBIndex', 'documentsDB', 'createIndex'],
            'vectorsDB' => ['vectorsDB', 'vectorsDBIndex', 'vectorsDB', 'createIndex'],
        ];
    }

    public function testCreateWithoutParentResource(): void
    {
        // Top-level resource (no parent) — e.g. a project-wide audit finding.
        $reportId = $this->createFixtureReport();
        $insightId = ID::unique();
        $body = $this->sampleInsight($insightId);
        unset($body['parentResourceType'], $body['parentResourceId']);
        $body['resourceType'] = 'projects';
        $body['resourceId'] = $this->getProject()['$id'];

        $insight = $this->createInsight($reportId, $body);

        $this->assertSame(201, $insight['headers']['status-code']);
        $this->assertSame('projects', $insight['body']['resourceType']);
        $this->assertEmpty($insight['body']['parentResourceType']);
        $this->assertEmpty($insight['body']['parentResourceId']);
        $this->assertEmpty($insight['body']['parentResourceInternalId']);

        $this->deleteInsight($reportId, $insightId);
        $this->deleteReport($reportId);
    }

    public function testCreateRejectsInvalidType(): void
    {
        $reportId = $this->createFixtureReport();
        $insight = $this->createInsight($reportId, [
            'insightId' => ID::unique(),
            'type' => 'unknownType',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
        ]);
        $this->assertSame(400, $insight['headers']['status-code']);

        $this->deleteReport($reportId);
    }

    public function testCreateRejectsInvalidSeverity(): void
    {
        $reportId = $this->createFixtureReport();
        $insight = $this->createInsight($reportId, [
            'insightId' => ID::unique(),
            'type' => 'databaseIndex',
            'severity' => 'catastrophic',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
        ]);
        $this->assertSame(400, $insight['headers']['status-code']);

        $this->deleteReport($reportId);
    }

    public function testCreateRejectsDuplicateId(): void
    {
        $reportId = $this->createFixtureReport();
        $insightId = ID::unique();

        $first = $this->createInsight($reportId, $this->sampleInsight($insightId));
        $this->assertSame(201, $first['headers']['status-code']);

        $second = $this->createInsight($reportId, $this->sampleInsight($insightId));
        $this->assertSame(409, $second['headers']['status-code']);
        $this->assertSame('insight_already_exists', $second['body']['type']);

        $this->deleteInsight($reportId, $insightId);
        $this->deleteReport($reportId);
    }

    public function testCreateRejectsUnknownReport(): void
    {
        // Path-level reportId doesn't exist — endpoint 404s before touching any
        // insight logic.
        $insight = $this->createInsight('definitely-missing', $this->sampleInsight());

        $this->assertSame(404, $insight['headers']['status-code']);
        $this->assertSame('report_not_found', $insight['body']['type']);
    }

    public function testCreateRejectsCTAWithEmptyLabel(): void
    {
        $reportId = $this->createFixtureReport();
        $insight = $this->createInsight($reportId, [
            'insightId' => ID::unique(),
            'type' => 'databaseIndex',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
            'ctas' => [
                ['label' => '', 'service' => 'databases', 'method' => 'createIndex'],
            ],
        ]);
        $this->assertSame(400, $insight['headers']['status-code']);

        $this->deleteReport($reportId);
    }

    public function testCreateRejectsCTAWithMissingMethod(): void
    {
        $reportId = $this->createFixtureReport();
        $insight = $this->createInsight($reportId, [
            'insightId' => ID::unique(),
            'type' => 'databaseIndex',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
            'ctas' => [
                ['label' => 'Missing method', 'service' => 'tablesDB'],
            ],
        ]);
        $this->assertSame(400, $insight['headers']['status-code']);

        $this->deleteReport($reportId);
    }

    public function testCreateRejectsCTAWithMissingService(): void
    {
        $reportId = $this->createFixtureReport();
        $insight = $this->createInsight($reportId, [
            'insightId' => ID::unique(),
            'type' => 'databaseIndex',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
            'ctas' => [
                ['label' => 'Missing service', 'method' => 'createIndex'],
            ],
        ]);
        $this->assertSame(400, $insight['headers']['status-code']);

        $this->deleteReport($reportId);
    }

    public function testCreateRejectsTooManyCTAs(): void
    {
        $reportId = $this->createFixtureReport();
        $ctas = [];
        for ($i = 0; $i < 17; $i++) {
            $ctas[] = [
                'label' => 'CTA ' . $i,
                'service' => 'databases',
                'method' => 'createIndex',
            ];
        }

        $insight = $this->createInsight($reportId, [
            'insightId' => ID::unique(),
            'type' => 'databaseIndex',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
            'ctas' => $ctas,
        ]);
        $this->assertSame(400, $insight['headers']['status-code']);

        $this->deleteReport($reportId);
    }

    #[Depends('testCreate')]
    public function testGet(array $data): array
    {
        $insight = $this->getInsight($data['reportId'], $data['insightId']);

        $this->assertSame(200, $insight['headers']['status-code']);
        $this->assertSame($data['insightId'], $insight['body']['$id']);
        $this->assertSame($data['reportId'], $insight['body']['reportId']);

        $missing = $this->getInsight($data['reportId'], 'missing');
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('insight_not_found', $missing['body']['type']);

        // Insight exists but caller used the wrong reportId — still 404.
        $wrongReport = $this->getInsight('definitely-missing', $data['insightId']);
        $this->assertSame(404, $wrongReport['headers']['status-code']);
        $this->assertSame('report_not_found', $wrongReport['body']['type']);

        return $data;
    }

    #[Depends('testGet')]
    public function testList(array $data): array
    {
        $list = $this->listInsights($data['reportId']);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        $this->assertNotEmpty($list['body']['insights']);
        // Every returned insight belongs to the path's report.
        foreach ($list['body']['insights'] as $insight) {
            $this->assertSame($data['reportId'], $insight['reportId']);
        }

        $byResourceType = $this->listInsights($data['reportId'], [
            'queries' => ['equal("resourceType", "indexes")'],
        ]);
        $this->assertSame(200, $byResourceType['headers']['status-code']);
        foreach ($byResourceType['body']['insights'] as $insight) {
            $this->assertSame('indexes', $insight['resourceType']);
        }

        $byParentResource = $this->listInsights($data['reportId'], [
            'queries' => [
                'equal("parentResourceType", "tables")',
                'equal("parentResourceId", "orders")',
            ],
        ]);
        $this->assertSame(200, $byParentResource['headers']['status-code']);
        foreach ($byParentResource['body']['insights'] as $insight) {
            $this->assertSame('tables', $insight['parentResourceType']);
            $this->assertSame('orders', $insight['parentResourceId']);
        }

        $byStatus = $this->listInsights($data['reportId'], [
            'queries' => ['equal("status", "active")'],
        ]);
        $this->assertSame(200, $byStatus['headers']['status-code']);
        foreach ($byStatus['body']['insights'] as $insight) {
            $this->assertSame('active', $insight['status']);
        }

        $byType = $this->listInsights($data['reportId'], [
            'queries' => ['equal("type", "tablesDBIndex")'],
        ]);
        $this->assertSame(200, $byType['headers']['status-code']);
        foreach ($byType['body']['insights'] as $insight) {
            $this->assertSame('tablesDBIndex', $insight['type']);
        }

        $bySeverity = $this->listInsights($data['reportId'], [
            'queries' => ['equal("severity", "warning")'],
        ]);
        $this->assertSame(200, $bySeverity['headers']['status-code']);
        foreach ($bySeverity['body']['insights'] as $insight) {
            $this->assertSame('warning', $insight['severity']);
        }

        // Listing under a non-existent report is a 404.
        $missingReport = $this->listInsights('definitely-missing');
        $this->assertSame(404, $missingReport['headers']['status-code']);
        $this->assertSame('report_not_found', $missingReport['body']['type']);

        return $data;
    }

    #[Depends('testList')]
    public function testListRejectsInvalidQueryAttribute(array $data): array
    {
        $invalid = $this->listInsights($data['reportId'], [
            'queries' => ['equal("unknownField", "x")'],
        ]);
        $this->assertSame(400, $invalid['headers']['status-code']);

        return $data;
    }

    #[Depends('testListRejectsInvalidQueryAttribute')]
    public function testListWithCursor(array $data): array
    {
        // Seed two extra insights under the same report so pagination has
        // something to chew through.
        $first = ID::unique();
        $second = ID::unique();
        $this->createInsight($data['reportId'], $this->sampleInsight($first));
        $this->createInsight($data['reportId'], $this->sampleInsight($second));

        $page1 = $this->listInsights($data['reportId'], [
            'queries' => ['limit(1)'],
        ]);
        $this->assertSame(200, $page1['headers']['status-code']);
        $this->assertCount(1, $page1['body']['insights']);

        $cursorId = $page1['body']['insights'][0]['$id'];
        $page2 = $this->listInsights($data['reportId'], [
            'queries' => ['limit(1)', 'cursorAfter("' . $cursorId . '")'],
        ]);
        $this->assertSame(200, $page2['headers']['status-code']);
        $this->assertCount(1, $page2['body']['insights']);
        $this->assertNotSame($cursorId, $page2['body']['insights'][0]['$id']);

        $missingCursor = $this->listInsights($data['reportId'], [
            'queries' => ['cursorAfter("definitely-missing")'],
        ]);
        $this->assertSame(400, $missingCursor['headers']['status-code']);

        $this->deleteInsight($data['reportId'], $first);
        $this->deleteInsight($data['reportId'], $second);

        return $data;
    }

    #[Depends('testListWithCursor')]
    public function testUpdate(array $data): array
    {
        $original = $this->getInsight($data['reportId'], $data['insightId'])['body'];

        $updated = $this->updateInsight($data['reportId'], $data['insightId'], [
            'severity' => 'critical',
        ]);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('critical', $updated['body']['severity']);

        // Analyzer-controlled fields preserved (regression for partial-document
        // overwrite). User Update only takes `severity` and `status`; everything
        // else flows through the manager Create endpoint.
        $this->assertSame($original['title'], $updated['body']['title']);
        $this->assertSame($original['summary'], $updated['body']['summary']);
        $this->assertSame($original['type'], $updated['body']['type']);
        $this->assertSame($original['resourceType'], $updated['body']['resourceType']);
        $this->assertSame($original['resourceId'], $updated['body']['resourceId']);
        $this->assertSame($original['parentResourceType'], $updated['body']['parentResourceType']);
        $this->assertSame($original['parentResourceId'], $updated['body']['parentResourceId']);
        $this->assertSame($original['reportId'], $updated['body']['reportId']);
        $this->assertSame($original['ctas'], $updated['body']['ctas']);

        return $data;
    }

    #[Depends('testUpdate')]
    public function testDismissViaUpdate(array $data): array
    {
        $dismissed = $this->updateInsight($data['reportId'], $data['insightId'], ['status' => 'dismissed']);

        $this->assertSame(200, $dismissed['headers']['status-code']);
        $this->assertSame('dismissed', $dismissed['body']['status']);
        $this->assertNotEmpty($dismissed['body']['dismissedAt']);
        $this->assertNotEmpty($dismissed['body']['dismissedBy']);

        $byDismissed = $this->listInsights($data['reportId'], [
            'queries' => ['equal("status", "dismissed")'],
        ]);
        $this->assertSame(200, $byDismissed['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $byDismissed['body']['total']);

        $undismiss = $this->updateInsight($data['reportId'], $data['insightId'], ['status' => 'active']);

        $this->assertSame(200, $undismiss['headers']['status-code']);
        $this->assertSame('active', $undismiss['body']['status']);
        $this->assertEmpty($undismiss['body']['dismissedAt']);
        $this->assertEmpty($undismiss['body']['dismissedBy']);

        return $data;
    }

    #[Depends('testDismissViaUpdate')]
    public function testUpdateMissing(array $data): array
    {
        // Real report, missing insight → insight_not_found.
        $missingInsight = $this->updateInsight($data['reportId'], 'missing', ['severity' => 'critical']);
        $this->assertSame(404, $missingInsight['headers']['status-code']);
        $this->assertSame('insight_not_found', $missingInsight['body']['type']);

        // Missing report → report_not_found before insight is even checked.
        $missingReport = $this->updateInsight('definitely-missing', $data['insightId'], ['severity' => 'critical']);
        $this->assertSame(404, $missingReport['headers']['status-code']);
        $this->assertSame('report_not_found', $missingReport['body']['type']);

        return $data;
    }

    #[Depends('testUpdateMissing')]
    public function testDelete(array $data): array
    {
        $delete = $this->deleteInsight($data['reportId'], $data['insightId']);
        $this->assertSame(204, $delete['headers']['status-code']);

        $missing = $this->getInsight($data['reportId'], $data['insightId']);
        $this->assertSame(404, $missing['headers']['status-code']);

        return $data;
    }

    #[Depends('testDelete')]
    public function testDeleteReportCascadesToInsights(array $data): void
    {
        $insightId = ID::unique();
        $create = $this->createInsight($data['reportId'], $this->sampleInsight($insightId));
        $this->assertSame(201, $create['headers']['status-code']);

        $deleteReport = $this->deleteReport($data['reportId']);
        $this->assertSame(204, $deleteReport['headers']['status-code']);

        $missingReport = $this->getReport($data['reportId']);
        $this->assertSame(404, $missingReport['headers']['status-code']);

        // The insight got cascaded too — both the parent path and the insight
        // itself are gone.
        $orphaned = $this->getInsight($data['reportId'], $insightId);
        $this->assertSame(404, $orphaned['headers']['status-code']);
    }

    public function testCreateRequiresServerKey(): void
    {
        // Auth check runs before the report fetch, so any reportId works for
        // this assertion.
        $unauthorized = $this->createInsight(ID::unique(), $this->sampleInsight(), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertSame(401, $unauthorized['headers']['status-code']);
    }

    public function testListSurvivesEmptyReport(): void
    {
        $reportId = $this->createFixtureReport();

        $list = $this->listInsights($reportId);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(0, $list['body']['total']);
        $this->assertEmpty($list['body']['insights']);

        $this->deleteReport($reportId);
    }
}
