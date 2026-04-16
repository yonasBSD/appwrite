<?php

namespace Tests\E2E\Services\TablesDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SchemaPolling;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Traits\DatabasesUrlHelpers;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class DatabasesNumericTypesTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use ApiTablesDB;
    use DatabasesUrlHelpers;
    use SchemaPolling;

    private static array $setupCache = [];

    /**
     * Setup database, table, and numeric columns for parallel-safe tests.
     */
    protected function setupDatabaseAndTable(): array
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(self::$setupCache[$cacheKey])) {
            return self::$setupCache[$cacheKey];
        }

        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ];

        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Numeric Types Test Database',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $headers, [
            'tableId' => ID::unique(),
            'name' => 'Numeric Types Table',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $tableId = $table['body']['$id'];

        // Create integer column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', $headers, [
            'key' => 'integer_field',
            'required' => false,
            'min' => -10,
            'max' => 10,
            'default' => 0,
        ]);

        // Create bigint column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/bigint', $headers, [
            'key' => 'bigint_field',
            'required' => false,
            'min' => -9007199254740991,
            'max' => 9007199254740991,
            'default' => 9007199254740000,
        ]);

        // Cache before waiting so that if waitForAllAttributes times out,
        // subsequent calls don't try to re-create the same columns (causing 409)
        self::$setupCache[$cacheKey] = [
            'databaseId' => $databaseId,
            'tableId' => $tableId,
        ];

        // Wait for all columns to be available
        $this->waitForAllAttributes($databaseId, $tableId);

        return self::$setupCache[$cacheKey];
    }

    /**
     * Setup database/table without caching so mutations (update/delete) don't
     * affect other tests that might be executed in a different order.
     */
    protected function setupFreshDatabaseAndTable(): array
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ];

        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Numeric Types Test Database',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $headers, [
            'tableId' => ID::unique(),
            'name' => 'Numeric Types Table',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $tableId = $table['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', $headers, [
            'key' => 'integer_field',
            'required' => false,
            'min' => -10,
            'max' => 10,
            'default' => 0,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/bigint', $headers, [
            'key' => 'bigint_field',
            'required' => false,
            'min' => -9007199254740991,
            'max' => 9007199254740991,
            'default' => 9007199254740000,
        ]);

        $this->waitForAllAttributes($databaseId, $tableId);

        return [
            'databaseId' => $databaseId,
            'tableId' => $tableId,
        ];
    }

    public function testCreateDatabase(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Numeric Types Test Database',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
    }

    public function testCreateTable(): void
    {
        $data = $this->setupDatabaseAndTable();

        $table = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['tableId'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $table['headers']['status-code']);
        $this->assertEquals($data['tableId'], $table['body']['$id']);
    }

    public function testGetIntegerAndBigIntColumns(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $integerColumn = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $integerColumn['headers']['status-code']);
        $this->assertEquals('integer_field', $integerColumn['body']['key']);
        $this->assertEquals('integer', $integerColumn['body']['type']);
        $this->assertEquals(false, $integerColumn['body']['required']);
        $this->assertEquals(false, $integerColumn['body']['array']);
        $this->assertEquals(-10, $integerColumn['body']['min']);
        $this->assertEquals(10, $integerColumn['body']['max']);
        $this->assertEquals(0, $integerColumn['body']['default']);

        $bigintColumn = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/bigint_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $bigintColumn['headers']['status-code']);
        $this->assertEquals('bigint_field', $bigintColumn['body']['key']);

        $this->assertEquals('bigint', $bigintColumn['body']['type']);
        $this->assertEquals(false, $bigintColumn['body']['required']);
        $this->assertEquals(false, $bigintColumn['body']['array']);
        $this->assertEquals(-9007199254740991, $bigintColumn['body']['min']);
        $this->assertEquals(9007199254740991, $bigintColumn['body']['max']);
        $this->assertEquals(9007199254740000, $bigintColumn['body']['default']);
    }

    public function testListColumnsWithNumericTypes(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $columns = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $columns['headers']['status-code']);
        $this->assertIsArray($columns['body']['columns']);
        $this->assertGreaterThan(0, $columns['body']['total']);

        $columnKeys = array_map(fn ($col) => $col['key'], $columns['body']['columns']);
        $this->assertContains('integer_field', $columnKeys);
        $this->assertContains('bigint_field', $columnKeys);

        $columnTypeByKey = [];
        foreach ($columns['body']['columns'] as $col) {
            $columnTypeByKey[$col['key']] = $col['type'];
        }

        $this->assertEquals('integer', $columnTypeByKey['integer_field']);
        $this->assertEquals('bigint', $columnTypeByKey['bigint_field']);
    }

    public function testCreateRowWithIntegerAndBigIntTypes(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'integer_field' => 5,
                'bigint_field' => 456,
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $row['headers']['status-code']);
        $this->assertEquals(5, $row['body']['integer_field']);
        $this->assertEquals(456, $row['body']['bigint_field']);
    }

    public function testUpdateIntegerAndBigIntColumns(): void
    {
        $data = $this->setupFreshDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Update integer column
        $updateInteger = $this->client->call(
            Client::METHOD_PATCH,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer/integer_field',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'required' => false,
                'min' => -20,
                'max' => 20,
                'default' => 3,
            ]
        );

        $this->assertEquals(200, $updateInteger['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $tableId) {
            $column = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer_field', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(200, $column['headers']['status-code']);
            $this->assertEquals(-20, $column['body']['min']);
            $this->assertEquals(20, $column['body']['max']);
            $this->assertEquals(3, $column['body']['default']);
        }, 30000, 250);

        // Update bigint column
        $updateBigint = $this->client->call(
            Client::METHOD_PATCH,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/bigint/bigint_field',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'required' => false,
                'min' => -999,
                'max' => 999,
                'default' => 10,
            ]
        );

        $this->assertEquals(200, $updateBigint['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $tableId) {
            $column = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/bigint_field', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(200, $column['headers']['status-code']);
            $this->assertEquals(-999, $column['body']['min']);
            $this->assertEquals(999, $column['body']['max']);
            $this->assertEquals(10, $column['body']['default']);
        }, 30000, 250);
    }

    public function testDeleteIntegerAndBigIntColumns(): void
    {
        $data = $this->setupFreshDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Delete integer column
        $deleteInteger = $this->client->call(
            Client::METHOD_DELETE,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer_field',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]
        );

        $this->assertEquals(204, $deleteInteger['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $tableId) {
            $column = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer_field', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(404, $column['headers']['status-code']);
        }, 30000, 250);

        // Delete bigint column
        $deleteBigint = $this->client->call(
            Client::METHOD_DELETE,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/bigint_field',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]
        );

        $this->assertEquals(204, $deleteBigint['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $tableId) {
            $column = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/bigint_field', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(404, $column['headers']['status-code']);
        }, 30000, 250);
    }
}
