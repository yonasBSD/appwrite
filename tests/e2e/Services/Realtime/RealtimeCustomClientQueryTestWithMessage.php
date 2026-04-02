<?php

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use WebSocket\Client as WebSocketClient;
use WebSocket\TimeoutException;

class RealtimeCustomClientQueryTestWithMessage extends Scope
{
    use ProjectCustom;
    use SideClient;
    use RealtimeQueryBase;

    protected function supportForCheckConnectionStatus(): bool
    {
        return false;
    }

    /**
     * Same signature as `RealtimeBase::getWebsocket()`, but:
     * - never sends queries in the URL (avoids URL length limits)
     * - once connected, updates the generated subscription using a bulk `type: "query"` message
     */
    private function getWebsocket(
        array $channels = [],
        array $headers = [],
        ?string $projectId = null,
        ?array $queries = null,
        int $timeout = 2
    ): WebSocketClient {
        if ($projectId === null) {
            $projectId = $this->getProject()['$id'];
        }

        $queryString = \http_build_query([
            'project' => $projectId,
            'channels' => $channels,
        ]);

        $client = new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . $queryString,
            [
                'headers' => $headers,
                'timeout' => $timeout,
            ]
        );
        $connected = \json_decode($client->receive(), true);
        $this->assertEquals('connected', $connected['type'] ?? null);

        if ($queries === null) {
            return $client;
        }

        $subscriptions = $connected['data']['subscriptions'] ?? [];
        $this->assertNotEmpty($subscriptions);
        $subscriptionId = $subscriptions[\array_key_first($subscriptions)];

        if ($queries === []) {
            $queries = [Query::select(['*'])->toString()];
        }

        $client->send(\json_encode([
            'type' => 'query',
            'data' => [[
                'subscriptionId' => $subscriptionId,
                'channels' => $channels,
                'queries' => $queries,
            ]],
        ]));

        $response = \json_decode($client->receive(), true);
        $this->assertEquals('response', $response['type'] ?? null);
        $this->assertEquals('query', $response['data']['to'] ?? null);
        $this->assertTrue($response['data']['success'] ?? false);
        $this->assertArrayHasKey('subscriptions', $response['data']);
        $this->assertIsArray($response['data']['subscriptions']);

        return $client;
    }

    public function testQueryMessageFiltersEvents(): void
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $userId = $user['$id'] ?? '';
        $projectId = $this->getProject()['$id'];

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Query Message Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Query Message Test Collection',
            'permissions' => [
                Permission::create(Role::user($userId)),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'status',
            'size' => 256,
            'required' => false,
        ]);

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/status', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        $targetDocumentId = ID::unique();
        $otherDocumentId = ID::unique();

        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::equal('$id', [$targetDocumentId])->toString(),
        ]);

        // Create matching document - should receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $targetDocumentId,
            'data' => [
                'status' => 'active',
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = \json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals($targetDocumentId, $event['data']['payload']['$id']);

        // Create non-matching document - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $otherDocumentId,
            'data' => [
                'status' => 'inactive',
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered by updated query');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();
    }
}
