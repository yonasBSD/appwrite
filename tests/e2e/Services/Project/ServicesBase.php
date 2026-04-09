<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;

trait ServicesBase
{
    /**
     * Optional services that can be toggled.
     */
    protected static array $optionalServices = [
        'account',
        'avatars',
        'databases',
        'tablesdb',
        'locale',
        'health',
        'project',
        'storage',
        'teams',
        'users',
        'vcs',
        'sites',
        'functions',
        'proxy',
        'graphql',
        'migrations',
        'messaging',
    ];

    // Success flow

    public function testDisableService(): void
    {
        foreach (self::$optionalServices as $service) {
            $response = $this->updateServiceStatus($service, false);

            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertSame(false, $response['body']['serviceStatusFor' . ucfirst($service)]);
        }

        // Cleanup
        foreach (self::$optionalServices as $service) {
            $this->updateServiceStatus($service, true);
        }
    }

    public function testEnableService(): void
    {
        // Disable first
        foreach (self::$optionalServices as $service) {
            $this->updateServiceStatus($service, false);
        }

        // Re-enable
        foreach (self::$optionalServices as $service) {
            $response = $this->updateServiceStatus($service, true);

            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertSame(true, $response['body']['serviceStatusFor' . ucfirst($service)]);
        }
    }

    public function testDisableServiceIdempotent(): void
    {
        $first = $this->updateServiceStatus('teams', false);
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertSame(false, $first['body']['serviceStatusForTeams']);

        $second = $this->updateServiceStatus('teams', false);
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame(false, $second['body']['serviceStatusForTeams']);

        // Cleanup
        $this->updateServiceStatus('teams', true);
    }

    public function testEnableServiceIdempotent(): void
    {
        $first = $this->updateServiceStatus('teams', true);
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertSame(true, $first['body']['serviceStatusForTeams']);

        $second = $this->updateServiceStatus('teams', true);
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame(true, $second['body']['serviceStatusForTeams']);
    }

    public function testDisabledServiceBlocksClientRequest(): void
    {
        $this->updateServiceStatus('teams', false);

        $response = $this->client->call(Client::METHOD_GET, '/teams', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertSame(403, $response['headers']['status-code']);
        $this->assertSame('general_service_disabled', $response['body']['type']);

        // Cleanup
        $this->updateServiceStatus('teams', true);
    }

    public function testEnabledServiceAllowsClientRequest(): void
    {
        $this->updateServiceStatus('teams', false);
        $this->updateServiceStatus('teams', true);

        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertSame(200, $response['headers']['status-code']);
    }

    public function testDisableOneServiceDoesNotAffectOther(): void
    {
        $this->updateServiceStatus('teams', false);

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertSame(200, $response['headers']['status-code']);

        // Cleanup
        $this->updateServiceStatus('teams', true);
    }

    public function testResponseModel(): void
    {
        $response = $this->updateServiceStatus('teams', false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('name', $response['body']);
        $this->assertArrayHasKey('serviceStatusForTeams', $response['body']);

        // Cleanup
        $this->updateServiceStatus('teams', true);
    }

    // Failure flow

    public function testUpdateServiceWithoutAuthentication(): void
    {
        $response = $this->updateServiceStatus('teams', false, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateServiceInvalidServiceId(): void
    {
        $response = $this->updateServiceStatus('invalid', false);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateServiceEmptyServiceId(): void
    {
        $response = $this->updateServiceStatus('', false);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    // Helpers

    protected function updateServiceStatus(string $serviceId, bool $enabled, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PUT, '/project/services/' . $serviceId . '/status', $headers, [
            'enabled' => $enabled,
        ]);
    }
}
