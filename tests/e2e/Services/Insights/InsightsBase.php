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

    public function testCreateReport(): array
    {
        $reportId = ID::unique();

        $response = $this->client->call(Client::METHOD_POST, '/reports', $this->serverHeaders(), [
            'reportId' => $reportId,
            'type' => 'databaseAnalyzer',
            'title' => 'Database analyzer report',
            'targetType' => 'databases',
            'target' => 'main',
            'categories' => ['performance'],
        ]);

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($reportId, $response['body']['$id']);
        $this->assertSame('databaseAnalyzer', $response['body']['type']);
        $this->assertSame('main', $response['body']['target']);

        return ['reportId' => $reportId];
    }

    /**
     * @depends testCreateReport
     */
    public function testGetReport(array $data): array
    {
        $reportId = $data['reportId'];

        $response = $this->client->call(Client::METHOD_GET, '/reports/' . $reportId, $this->serverHeaders());
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($reportId, $response['body']['$id']);

        $missing = $this->client->call(Client::METHOD_GET, '/reports/missing', $this->serverHeaders());
        $this->assertSame(404, $missing['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testGetReport
     */
    public function testListReports(array $data): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/reports', $this->serverHeaders());
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);

        return $data;
    }

    /**
     * @depends testListReports
     */
    public function testCreate(array $data): array
    {
        $insightId = ID::unique();

        $response = $this->client->call(Client::METHOD_POST, '/insights', $this->serverHeaders(), [
            'insightId' => $insightId,
            'reportId' => $data['reportId'],
            'type' => 'databaseIndex',
            'severity' => 'warning',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Missing index on collection orders',
            'summary' => 'Queries against `orders.status` are scanning the full collection.',
            'payload' => ['databaseId' => 'main', 'collectionId' => 'orders'],
            'ctas' => [[
                'id' => 'createIndex',
                'label' => 'Create missing index',
                'action' => 'databases.createIndex',
                'params' => [
                    'databaseId' => 'main',
                    'collectionId' => 'orders',
                    'key' => '_idx_status',
                    'type' => 'key',
                    'attributes' => ['status'],
                ],
            ]],
        ]);

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($insightId, $response['body']['$id']);
        $this->assertSame($data['reportId'], $response['body']['reportId']);
        $this->assertSame('databaseIndex', $response['body']['type']);
        $this->assertSame('warning', $response['body']['severity']);
        $this->assertSame('databases', $response['body']['resourceType']);
        $this->assertSame('main', $response['body']['resourceId']);
        $this->assertSame('Missing index on collection orders', $response['body']['title']);
        $this->assertCount(1, $response['body']['ctas']);
        $this->assertSame('createIndex', $response['body']['ctas'][0]['id']);
        $this->assertSame('databases.createIndex', $response['body']['ctas'][0]['action']);

        return $data + ['insightId' => $insightId];
    }

    public function testCreateRejectsDuplicateCTAIds(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/insights', $this->serverHeaders(), [
            'insightId' => ID::unique(),
            'type' => 'databaseIndex',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
            'ctas' => [
                ['id' => 'dup', 'label' => 'A', 'action' => 'databases.createIndex'],
                ['id' => 'dup', 'label' => 'B', 'action' => 'databases.createIndex'],
            ],
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testCreateRejectsUnknownReport(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/insights', $this->serverHeaders(), [
            'insightId' => ID::unique(),
            'reportId' => 'definitely-missing',
            'type' => 'databaseIndex',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
        ]);

        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame('report_not_found', $response['body']['type']);
    }

    /**
     * @depends testCreate
     */
    public function testGet(array $data): array
    {
        $insightId = $data['insightId'];

        $response = $this->client->call(Client::METHOD_GET, '/insights/' . $insightId, $this->serverHeaders());

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($insightId, $response['body']['$id']);

        $missing = $this->client->call(Client::METHOD_GET, '/insights/missing', $this->serverHeaders());
        $this->assertSame(404, $missing['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testGet
     */
    public function testList(array $data): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/insights', $this->serverHeaders());

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);
        $this->assertNotEmpty($response['body']['insights']);

        $filtered = $this->client->call(Client::METHOD_GET, '/insights', $this->serverHeaders(), [
            'queries' => [
                'equal("resourceType", "databases")',
            ],
        ]);
        $this->assertSame(200, $filtered['headers']['status-code']);
        foreach ($filtered['body']['insights'] as $insight) {
            $this->assertSame('databases', $insight['resourceType']);
        }

        $byStatus = $this->client->call(Client::METHOD_GET, '/insights', $this->serverHeaders(), [
            'queries' => [
                'equal("status", "active")',
            ],
        ]);
        $this->assertSame(200, $byStatus['headers']['status-code']);
        foreach ($byStatus['body']['insights'] as $insight) {
            $this->assertSame('active', $insight['status']);
        }

        return $data;
    }

    /**
     * @depends testList
     */
    public function testUpdate(array $data): array
    {
        $insightId = $data['insightId'];

        $response = $this->client->call(Client::METHOD_PATCH, '/insights/' . $insightId, $this->serverHeaders(), [
            'severity' => 'critical',
            'summary' => 'Updated summary.',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('critical', $response['body']['severity']);
        $this->assertSame('Updated summary.', $response['body']['summary']);
        $this->assertSame('Missing index on collection orders', $response['body']['title']);

        return $data;
    }

    /**
     * @depends testUpdate
     */
    public function testUpdateRejectsDuplicateCTAIds(array $data): array
    {
        $insightId = $data['insightId'];

        $response = $this->client->call(Client::METHOD_PATCH, '/insights/' . $insightId, $this->serverHeaders(), [
            'ctas' => [
                ['id' => 'dup', 'label' => 'A', 'action' => 'databases.createIndex'],
                ['id' => 'dup', 'label' => 'B', 'action' => 'databases.createIndex'],
            ],
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        return $data;
    }

    /**
     * @depends testUpdateRejectsDuplicateCTAIds
     */
    public function testDismissViaUpdate(array $data): array
    {
        $insightId = $data['insightId'];

        $response = $this->client->call(Client::METHOD_PATCH, '/insights/' . $insightId, $this->serverHeaders(), [
            'status' => 'dismissed',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('dismissed', $response['body']['status']);
        $this->assertNotEmpty($response['body']['dismissedAt']);

        $undismiss = $this->client->call(Client::METHOD_PATCH, '/insights/' . $insightId, $this->serverHeaders(), [
            'status' => 'active',
        ]);

        $this->assertSame(200, $undismiss['headers']['status-code']);
        $this->assertSame('active', $undismiss['body']['status']);
        $this->assertEmpty($undismiss['body']['dismissedAt']);

        return $data;
    }

    public function testCreateRequiresServerKey(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/insights', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'insightId' => ID::unique(),
            'type' => 'databaseIndex',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Should not be created',
        ]);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testDelete(): void
    {
        $insightId = ID::unique();

        $create = $this->client->call(Client::METHOD_POST, '/insights', $this->serverHeaders(), [
            'insightId' => $insightId,
            'type' => 'databaseIndex',
            'resourceType' => 'databases',
            'resourceId' => 'main',
            'title' => 'Insight to be deleted',
        ]);
        $this->assertSame(201, $create['headers']['status-code']);

        $delete = $this->client->call(Client::METHOD_DELETE, '/insights/' . $insightId, $this->serverHeaders());
        $this->assertSame(204, $delete['headers']['status-code']);

        $missing = $this->client->call(Client::METHOD_GET, '/insights/' . $insightId, $this->serverHeaders());
        $this->assertSame(404, $missing['headers']['status-code']);
    }

    public function testDeleteReportCascadesToInsights(): void
    {
        $reportId = ID::unique();
        $createReport = $this->client->call(Client::METHOD_POST, '/reports', $this->serverHeaders(), [
            'reportId' => $reportId,
            'type' => 'audit',
            'title' => 'Cascade-target report',
            'targetType' => 'sites',
            'target' => 'home',
        ]);
        $this->assertSame(201, $createReport['headers']['status-code']);

        $insightId = ID::unique();
        $createInsight = $this->client->call(Client::METHOD_POST, '/insights', $this->serverHeaders(), [
            'insightId' => $insightId,
            'reportId' => $reportId,
            'type' => 'sitePerformance',
            'resourceType' => 'sites',
            'resourceId' => 'home',
            'title' => 'Largest contentful paint regressed',
        ]);
        $this->assertSame(201, $createInsight['headers']['status-code']);

        $deleteReport = $this->client->call(Client::METHOD_DELETE, '/reports/' . $reportId, $this->serverHeaders());
        $this->assertSame(204, $deleteReport['headers']['status-code']);

        $orphaned = $this->client->call(Client::METHOD_GET, '/insights/' . $insightId, $this->serverHeaders());
        $this->assertSame(404, $orphaned['headers']['status-code']);
    }
}
