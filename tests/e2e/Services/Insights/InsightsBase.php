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

    public function testCreate(): array
    {
        $insightId = ID::unique();

        $response = $this->client->call(Client::METHOD_POST, '/insights', $this->serverHeaders(), [
            'insightId' => $insightId,
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
                'action' => 'databases.indexes.create',
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
        $this->assertSame('databaseIndex', $response['body']['type']);
        $this->assertSame('warning', $response['body']['severity']);
        $this->assertSame('databases', $response['body']['resourceType']);
        $this->assertSame('main', $response['body']['resourceId']);
        $this->assertSame('Missing index on collection orders', $response['body']['title']);
        $this->assertCount(1, $response['body']['ctas']);
        $this->assertSame('createIndex', $response['body']['ctas'][0]['id']);

        return ['insightId' => $insightId];
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

    /**
     * @depends testDismissViaUpdate
     */
    public function testCreateCTAExecution(array $data): void
    {
        $insightId = $data['insightId'];

        $missingCTA = $this->client->call(Client::METHOD_POST, '/insights/' . $insightId . '/ctas/missing/executions', $this->serverHeaders());
        $this->assertSame(404, $missingCTA['headers']['status-code']);
        $this->assertSame('insight_cta_not_found', $missingCTA['body']['type']);

        $response = $this->client->call(Client::METHOD_POST, '/insights/' . $insightId . '/ctas/createIndex/executions', $this->serverHeaders());

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($insightId, $response['body']['insightId']);
        $this->assertSame('createIndex', $response['body']['ctaId']);
        $this->assertSame('databases.indexes.create', $response['body']['action']);
        $this->assertContains($response['body']['status'], ['succeeded', 'failed']);
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
}
