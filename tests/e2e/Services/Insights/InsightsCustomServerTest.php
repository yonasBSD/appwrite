<?php

namespace Tests\E2E\Services\Insights;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class InsightsCustomServerTest extends Scope
{
    use InsightsBase;
    use ProjectCustom;
    use SideServer;

    public function testReadWithAdvisorScopes(): void
    {
        $userKey = $this->getNewKey([
            'insights.read',
            'reports.read',
        ]);

        $listed = $this->client->call(
            Client::METHOD_GET,
            '/reports',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $userKey,
            ]
        );

        $this->assertSame(200, $listed['headers']['status-code']);

        $create = $this->client->call(
            Client::METHOD_POST,
            '/reports',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $userKey,
            ],
            [
                'reportId' => ID::unique(),
                'type' => 'audit',
                'title' => 'Read-only check',
                'targetType' => 'sites',
                'target' => 'home',
            ]
        );

        $this->assertSame(404, $create['headers']['status-code']);
    }
}
