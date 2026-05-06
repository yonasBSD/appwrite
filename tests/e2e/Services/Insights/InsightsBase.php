<?php

namespace Tests\E2E\Services\Insights;

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

    protected function createReport(array $body, array $headers = null): array
    {
        return $this->client->call(Client::METHOD_POST, '/reports', $headers ?? $this->serverHeaders(), $body);
    }

    protected function getReport(string $reportId, array $headers = null): array
    {
        return $this->client->call(Client::METHOD_GET, '/reports/' . $reportId, $headers ?? $this->serverHeaders());
    }

    protected function listReports(array $params = [], array $headers = null): array
    {
        return $this->client->call(Client::METHOD_GET, '/reports', $headers ?? $this->serverHeaders(), $params);
    }

    protected function updateReport(string $reportId, array $body, array $headers = null): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/reports/' . $reportId, $headers ?? $this->serverHeaders(), $body);
    }

    protected function deleteReport(string $reportId, array $headers = null): array
    {
        return $this->client->call(Client::METHOD_DELETE, '/reports/' . $reportId, $headers ?? $this->serverHeaders());
    }

    protected function createInsight(array $body, array $headers = null): array
    {
        // Manager-only endpoint — internal Appwrite services ingest here, not user SDKs.
        return $this->client->call(Client::METHOD_POST, '/manager/insights', $headers ?? $this->serverHeaders(), $body);
    }

    protected function getInsight(string $insightId, array $headers = null): array
    {
        return $this->client->call(Client::METHOD_GET, '/insights/' . $insightId, $headers ?? $this->serverHeaders());
    }

    protected function listInsights(array $params = [], array $headers = null): array
    {
        return $this->client->call(Client::METHOD_GET, '/insights', $headers ?? $this->serverHeaders(), $params);
    }

    protected function updateInsight(string $insightId, array $body, array $headers = null): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/insights/' . $insightId, $headers ?? $this->serverHeaders(), $body);
    }

    protected function deleteInsight(string $insightId, array $headers = null): array
    {
        return $this->client->call(Client::METHOD_DELETE, '/insights/' . $insightId, $headers ?? $this->serverHeaders());
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
    protected function sampleCTA(string $key = 'createIndex', string $engine = 'tablesDB'): array
    {
        $base = [
            'key' => $key,
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

    protected function sampleInsight(string $insightId = null, string $reportId = null, string $engine = 'tablesDB'): array
    {
        $type = match ($engine) {
            'databases' => 'databaseIndex',
            'tablesDB' => 'tablesDBIndex',
            'documentsDB' => 'documentsDBIndex',
            'vectorsDB' => 'vectorsDBIndex',
            default => throw new \InvalidArgumentException("Unknown engine: {$engine}"),
        };

        // The insight is *about* a missing index, contained within a table/collection.
        // resourceType=indexes points at the index that should exist; the parent
        // points at the table/collection that owns it.
        $parentResourceType = match ($engine) {
            'databases' => 'collections',
            'tablesDB' => 'tables',
            'documentsDB' => 'collections',
            'vectorsDB' => 'collections',
            default => 'collections',
        };

        $body = [
            'insightId' => $insightId ?? ID::unique(),
            'type' => $type,
            'severity' => 'warning',
            'resourceType' => 'indexes',
            'resourceId' => '_idx_status',
            'parentResourceType' => $parentResourceType,
            'parentResourceId' => 'orders',
            'title' => 'Missing index on collection orders',
            'summary' => 'Queries against `orders.status` are scanning the full collection.',
            'payload' => ['databaseId' => 'main', 'engine' => $engine],
            'ctas' => [$this->sampleCTA('createIndex', $engine)],
        ];

        if ($reportId !== null) {
            $body['reportId'] = $reportId;
        }

        return $body;
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

        // cleanup
        $this->deleteReport($reportId);
    }

    /**
     * @depends testCreateReport
     */
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

    /**
     * @depends testGetReport
     */
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

    /**
     * @depends testListReports
     */
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

        // Unchanged fields preserved
        $this->assertSame($original['body']['type'], $updated['body']['type']);
        $this->assertSame($original['body']['target'], $updated['body']['target']);
        $this->assertSame($original['body']['targetType'], $updated['body']['targetType']);

        $missing = $this->updateReport('missing', ['title' => 'x']);
        $this->assertSame(404, $missing['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUpdateReport
     */
    public function testCreate(array $data): array
    {
        $insightId = ID::unique();

        $insight = $this->createInsight($this->sampleInsight($insightId, $data['reportId'], 'tablesDB'));

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
        $this->assertSame('createIndex', $insight['body']['ctas'][0]['key']);
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
     * Each engine — legacy databases, tablesDB, documentsDB, vectorsDB — should be
     * createable with its own insight type and a CTA whose service+method points
     * at the matching public API.
     *
     * @dataProvider engineMatrixProvider
     */
    public function testCreateForEachEngine(string $engine, string $expectedType, string $expectedService, string $expectedMethod): void
    {
        $insightId = ID::unique();

        $insight = $this->createInsight($this->sampleInsight($insightId, null, $engine));

        $this->assertSame(201, $insight['headers']['status-code']);
        $this->assertSame($expectedType, $insight['body']['type']);
        $this->assertSame($expectedService, $insight['body']['ctas'][0]['service']);
        $this->assertSame($expectedMethod, $insight['body']['ctas'][0]['method']);

        $this->deleteInsight($insightId);
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

    public function testCreateWithoutReport(): void
    {
        $insightId = ID::unique();

        $insight = $this->createInsight($this->sampleInsight($insightId));

        $this->assertSame(201, $insight['headers']['status-code']);
        $this->assertSame($insightId, $insight['body']['$id']);
        $this->assertEmpty($insight['body']['reportId']);

        $this->deleteInsight($insightId);
    }

    public function testCreateWithoutParentResource(): void
    {
        // Top-level resource (no parent) — e.g. a project-wide audit finding.
        $insightId = ID::unique();
        $body = $this->sampleInsight($insightId);
        unset($body['parentResourceType'], $body['parentResourceId']);
        $body['resourceType'] = 'projects';
        $body['resourceId'] = $this->getProject()['$id'];

        $insight = $this->createInsight($body);

        $this->assertSame(201, $insight['headers']['status-code']);
        $this->assertSame('projects', $insight['body']['resourceType']);
        $this->assertEmpty($insight['body']['parentResourceType']);
        $this->assertEmpty($insight['body']['parentResourceId']);
        $this->assertEmpty($insight['body']['parentResourceInternalId']);

        $this->deleteInsight($insightId);
    }

    public function testCreateRejectsInvalidType(): void
    {
        $insight = $this->createInsight([
            'insightId' => ID::unique(),
            'type' => 'unknownType',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
        ]);
        $this->assertSame(400, $insight['headers']['status-code']);
    }

    public function testCreateRejectsInvalidSeverity(): void
    {
        $insight = $this->createInsight([
            'insightId' => ID::unique(),
            'type' => 'databaseIndex',
            'severity' => 'catastrophic',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
        ]);
        $this->assertSame(400, $insight['headers']['status-code']);
    }

    public function testCreateRejectsDuplicateId(): void
    {
        $insightId = ID::unique();

        $first = $this->createInsight($this->sampleInsight($insightId));
        $this->assertSame(201, $first['headers']['status-code']);

        $second = $this->createInsight($this->sampleInsight($insightId));
        $this->assertSame(409, $second['headers']['status-code']);
        $this->assertSame('insight_already_exists', $second['body']['type']);

        $this->deleteInsight($insightId);
    }

    public function testCreateRejectsUnknownReport(): void
    {
        $insight = $this->createInsight($this->sampleInsight(null, 'definitely-missing'));

        $this->assertSame(404, $insight['headers']['status-code']);
        $this->assertSame('report_not_found', $insight['body']['type']);
    }

    public function testCreateRejectsDuplicateCTAIds(): void
    {
        $insight = $this->createInsight([
            'insightId' => ID::unique(),
            'type' => 'databaseIndex',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
            'ctas' => [
                ['key' => 'dup', 'label' => 'A', 'service' => 'databases', 'method' => 'createIndex'],
                ['key' => 'dup', 'label' => 'B', 'service' => 'databases', 'method' => 'createIndex'],
            ],
        ]);

        $this->assertSame(400, $insight['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $insight['body']['type']);
    }

    public function testCreateRejectsCTAWithEmptyFields(): void
    {
        $insight = $this->createInsight([
            'insightId' => ID::unique(),
            'type' => 'databaseIndex',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
            'ctas' => [
                ['key' => '', 'label' => 'Has empty id', 'service' => 'databases', 'method' => 'createIndex'],
            ],
        ]);

        $this->assertSame(400, $insight['headers']['status-code']);
    }

    public function testCreateRejectsCTAWithMissingMethod(): void
    {
        $insight = $this->createInsight([
            'insightId' => ID::unique(),
            'type' => 'databaseIndex',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
            'ctas' => [
                ['key' => 'createIndex', 'label' => 'Missing method', 'service' => 'tablesDB'],
            ],
        ]);

        $this->assertSame(400, $insight['headers']['status-code']);
    }

    public function testCreateRejectsCTAWithMissingService(): void
    {
        $insight = $this->createInsight([
            'insightId' => ID::unique(),
            'type' => 'databaseIndex',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
            'ctas' => [
                ['key' => 'createIndex', 'label' => 'Missing service', 'method' => 'createIndex'],
            ],
        ]);

        $this->assertSame(400, $insight['headers']['status-code']);
    }

    public function testCreateRejectsTooManyCTAs(): void
    {
        $ctas = [];
        for ($i = 0; $i < 17; $i++) {
            $ctas[] = [
                'key' => 'cta-' . $i,
                'label' => 'CTA ' . $i,
                'service' => 'databases',
                'method' => 'createIndex',
            ];
        }

        $insight = $this->createInsight([
            'insightId' => ID::unique(),
            'type' => 'databaseIndex',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
            'ctas' => $ctas,
        ]);

        $this->assertSame(400, $insight['headers']['status-code']);
    }

    /**
     * @depends testCreate
     */
    public function testGet(array $data): array
    {
        $insight = $this->getInsight($data['insightId']);

        $this->assertSame(200, $insight['headers']['status-code']);
        $this->assertSame($data['insightId'], $insight['body']['$id']);
        $this->assertSame($data['reportId'], $insight['body']['reportId']);

        $missing = $this->getInsight('missing');
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('insight_not_found', $missing['body']['type']);

        return $data;
    }

    /**
     * @depends testGet
     */
    public function testList(array $data): array
    {
        $list = $this->listInsights();
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        $this->assertNotEmpty($list['body']['insights']);

        $byResourceType = $this->listInsights([
            'queries' => ['equal("resourceType", "indexes")'],
        ]);
        $this->assertSame(200, $byResourceType['headers']['status-code']);
        foreach ($byResourceType['body']['insights'] as $insight) {
            $this->assertSame('indexes', $insight['resourceType']);
        }

        $byParentResource = $this->listInsights([
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

        $byStatus = $this->listInsights([
            'queries' => ['equal("status", "active")'],
        ]);
        $this->assertSame(200, $byStatus['headers']['status-code']);
        foreach ($byStatus['body']['insights'] as $insight) {
            $this->assertSame('active', $insight['status']);
        }

        $byType = $this->listInsights([
            'queries' => ['equal("type", "tablesDBIndex")'],
        ]);
        $this->assertSame(200, $byType['headers']['status-code']);
        foreach ($byType['body']['insights'] as $insight) {
            $this->assertSame('tablesDBIndex', $insight['type']);
        }

        $bySeverity = $this->listInsights([
            'queries' => ['equal("severity", "warning")'],
        ]);
        $this->assertSame(200, $bySeverity['headers']['status-code']);
        foreach ($bySeverity['body']['insights'] as $insight) {
            $this->assertSame('warning', $insight['severity']);
        }

        $byReport = $this->listInsights([
            'queries' => ['equal("reportId", "' . $data['reportId'] . '")'],
        ]);
        $this->assertSame(200, $byReport['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $byReport['body']['total']);
        foreach ($byReport['body']['insights'] as $insight) {
            $this->assertSame($data['reportId'], $insight['reportId']);
        }

        return $data;
    }

    /**
     * @depends testList
     */
    public function testListRejectsInvalidQueryAttribute(array $data): array
    {
        $invalid = $this->listInsights([
            'queries' => ['equal("unknownField", "x")'],
        ]);
        $this->assertSame(400, $invalid['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testListRejectsInvalidQueryAttribute
     */
    public function testListWithCursor(array $data): array
    {
        // Seed two extra insights so pagination has something to chew through
        $first = ID::unique();
        $second = ID::unique();
        $this->createInsight($this->sampleInsight($first));
        $this->createInsight($this->sampleInsight($second));

        $page1 = $this->listInsights([
            'queries' => ['limit(1)'],
        ]);
        $this->assertSame(200, $page1['headers']['status-code']);
        $this->assertCount(1, $page1['body']['insights']);

        $cursorId = $page1['body']['insights'][0]['$id'];
        $page2 = $this->listInsights([
            'queries' => ['limit(1)', 'cursorAfter("' . $cursorId . '")'],
        ]);
        $this->assertSame(200, $page2['headers']['status-code']);
        $this->assertCount(1, $page2['body']['insights']);
        $this->assertNotSame($cursorId, $page2['body']['insights'][0]['$id']);

        $missingCursor = $this->listInsights([
            'queries' => ['cursorAfter("definitely-missing")'],
        ]);
        $this->assertSame(400, $missingCursor['headers']['status-code']);

        $this->deleteInsight($first);
        $this->deleteInsight($second);

        return $data;
    }

    /**
     * @depends testListWithCursor
     */
    public function testUpdate(array $data): array
    {
        $original = $this->getInsight($data['insightId'])['body'];

        $updated = $this->updateInsight($data['insightId'], [
            'severity' => 'critical',
            'summary' => 'Updated summary.',
        ]);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('critical', $updated['body']['severity']);
        $this->assertSame('Updated summary.', $updated['body']['summary']);

        // Untouched fields preserved (regression for partial-document overwrite)
        $this->assertSame($original['title'], $updated['body']['title']);
        $this->assertSame($original['type'], $updated['body']['type']);
        $this->assertSame($original['resourceType'], $updated['body']['resourceType']);
        $this->assertSame($original['resourceId'], $updated['body']['resourceId']);
        $this->assertSame($original['parentResourceType'], $updated['body']['parentResourceType']);
        $this->assertSame($original['parentResourceId'], $updated['body']['parentResourceId']);
        $this->assertSame($original['reportId'], $updated['body']['reportId']);
        $this->assertSame($original['ctas'], $updated['body']['ctas']);
        $this->assertSame($original['payload'], $updated['body']['payload']);

        return $data;
    }

    /**
     * @depends testUpdate
     */
    public function testDismissViaUpdate(array $data): array
    {
        $dismissed = $this->updateInsight($data['insightId'], ['status' => 'dismissed']);

        $this->assertSame(200, $dismissed['headers']['status-code']);
        $this->assertSame('dismissed', $dismissed['body']['status']);
        $this->assertNotEmpty($dismissed['body']['dismissedAt']);
        $this->assertNotEmpty($dismissed['body']['dismissedBy']);

        $byDismissed = $this->listInsights([
            'queries' => ['equal("status", "dismissed")'],
        ]);
        $this->assertSame(200, $byDismissed['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $byDismissed['body']['total']);

        $undismiss = $this->updateInsight($data['insightId'], ['status' => 'active']);

        $this->assertSame(200, $undismiss['headers']['status-code']);
        $this->assertSame('active', $undismiss['body']['status']);
        $this->assertEmpty($undismiss['body']['dismissedAt']);
        $this->assertEmpty($undismiss['body']['dismissedBy']);

        return $data;
    }

    /**
     * @depends testDismissViaUpdate
     */
    public function testUpdateMissing(array $data): array
    {
        $missing = $this->updateInsight('missing', ['severity' => 'critical']);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('insight_not_found', $missing['body']['type']);

        return $data;
    }

    /**
     * @depends testUpdateMissing
     */
    public function testDelete(array $data): array
    {
        $delete = $this->deleteInsight($data['insightId']);
        $this->assertSame(204, $delete['headers']['status-code']);

        $missing = $this->getInsight($data['insightId']);
        $this->assertSame(404, $missing['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testDelete
     */
    public function testDeleteReportCascadesToInsights(array $data): void
    {
        $insightId = ID::unique();
        $create = $this->createInsight($this->sampleInsight($insightId, $data['reportId']));
        $this->assertSame(201, $create['headers']['status-code']);

        $deleteReport = $this->deleteReport($data['reportId']);
        $this->assertSame(204, $deleteReport['headers']['status-code']);

        $missingReport = $this->getReport($data['reportId']);
        $this->assertSame(404, $missingReport['headers']['status-code']);

        $orphaned = $this->getInsight($insightId);
        $this->assertSame(404, $orphaned['headers']['status-code']);
    }

    public function testCreateRequiresServerKey(): void
    {
        $unauthorized = $this->createInsight($this->sampleInsight(), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertSame(401, $unauthorized['headers']['status-code']);
    }

    public function testCreateRequiresManagerScope(): void
    {
        // A server key with insights.read + insights.write but NOT insights.manager
        // must be rejected — Create lives behind /v1/manager/insights and only
        // internal Appwrite services hold the manager scope.
        $userKey = $this->getNewKey([
            'insights.read',
            'insights.write',
        ]);

        $rejected = $this->createInsight($this->sampleInsight(), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $userKey,
        ]);

        $this->assertSame(401, $rejected['headers']['status-code']);
    }

    public function testListSurvivesEmptyDatabase(): void
    {
        $list = $this->listInsights([
            'queries' => ['equal("type", "siteSeo")'],
        ]);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(0, $list['body']['total']);
        $this->assertEmpty($list['body']['insights']);
    }
}
