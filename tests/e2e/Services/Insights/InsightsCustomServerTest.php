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

    public function testCreateRequiresManagerScope(): void
    {
        // A server key with insights.read + insights.write but NOT
        // insights.manager must be rejected — Create lives behind
        // /v1/manager/reports/:reportId/insights and only internal Appwrite
        // services hold the manager scope.
        $userKey = $this->getNewKey([
            'insights.read',
            'insights.write',
        ]);

        $rejected = $this->client->call(
            Client::METHOD_POST,
            '/manager/reports/' . ID::unique() . '/insights',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $userKey,
            ],
            $this->sampleInsight()
        );

        $this->assertSame(401, $rejected['headers']['status-code']);
    }
}
