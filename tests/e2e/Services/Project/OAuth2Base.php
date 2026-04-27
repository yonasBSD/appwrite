<?php

namespace Tests\E2E\Services\Project;

use PHPUnit\Framework\Attributes\Before;
use Tests\E2E\Client;

trait OAuth2Base
{
    /**
     * Reset providers we mutate in tests back to a known empty/disabled state.
     * The ProjectCustom trait reuses the same project across tests in a class,
     * and the OAuth2 PATCH endpoint is additive (omitted fields are preserved),
     * so without a reset state would leak between tests.
     */
    #[Before(priority: -1)]
    protected function resetProjectOAuth2(): void
    {
        $this->updateOAuth2('amazon', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // List OAuth2 providers
    // =========================================================================

    public function testListOAuth2Providers(): void
    {
        $response = $this->listOAuth2Providers();

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('total', $response['body']);
        $this->assertArrayHasKey('providers', $response['body']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertSame($response['body']['total'], \count($response['body']['providers']));
    }

    public function testListOAuth2ProvidersIncludesKnownProviders(): void
    {
        $response = $this->listOAuth2Providers();

        $this->assertSame(200, $response['headers']['status-code']);

        $ids = \array_column($response['body']['providers'], '$id');

        // Spot-check a representative cross-section of providers across all
        // provider shapes (plain, multi-field, sandboxed, custom param names).
        $expected = [
            'github',
            'amazon',
            'apple',
            'auth0',
            'authentik',
            'gitlab',
            'oidc',
            'okta',
            'microsoft',
            'dropbox',
            'paypalSandbox',
            'kick',
        ];

        foreach ($expected as $providerId) {
            $this->assertContains($providerId, $ids, "Missing provider {$providerId} in listOAuth2Providers response");
        }
    }

    public function testListOAuth2ProvidersResponseShape(): void
    {
        $response = $this->listOAuth2Providers();

        $this->assertSame(200, $response['headers']['status-code']);

        foreach ($response['body']['providers'] as $provider) {
            $this->assertArrayHasKey('$id', $provider);
            $this->assertArrayHasKey('enabled', $provider);
            $this->assertIsString($provider['$id']);
            $this->assertIsBool($provider['enabled']);
        }
    }

    public function testListOAuth2ProvidersClientSecretsNotExposed(): void
    {
        // Seed credentials so the list cannot trivially return empty values.
        $this->updateOAuth2('amazon', [
            'clientId' => 'amzn1.application-oa2-client.testListSeed',
            'clientSecret' => 'super-secret-must-not-leak',
            'enabled' => false,
        ]);

        $response = $this->listOAuth2Providers();

        $this->assertSame(200, $response['headers']['status-code']);

        $matched = false;
        foreach ($response['body']['providers'] as $provider) {
            if ($provider['$id'] !== 'amazon') {
                continue;
            }

            $matched = true;
            $this->assertSame('amzn1.application-oa2-client.testListSeed', $provider['clientId']);
            $this->assertSame('', $provider['clientSecret']);
        }

        $this->assertTrue($matched, 'List did not include the seeded provider.');
    }

    public function testListOAuth2ProvidersWithoutAuthentication(): void
    {
        $response = $this->listOAuth2Providers(authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Get OAuth2 provider
    // =========================================================================

    public function testGetOAuth2Provider(): void
    {
        $response = $this->getOAuth2Provider('github');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('github', $response['body']['$id']);
        $this->assertArrayHasKey('enabled', $response['body']);
        $this->assertArrayHasKey('clientId', $response['body']);
        $this->assertArrayHasKey('clientSecret', $response['body']);
        $this->assertSame('', $response['body']['clientSecret']);
    }

    public function testGetOAuth2ProviderClientSecretWriteOnly(): void
    {
        $this->updateOAuth2('amazon', [
            'clientId' => 'amzn1.application-oa2-client.getSecretCheck',
            'clientSecret' => 'must-never-be-returned',
            'enabled' => false,
        ]);

        $response = $this->getOAuth2Provider('amazon');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('amzn1.application-oa2-client.getSecretCheck', $response['body']['clientId']);
        $this->assertSame('', $response['body']['clientSecret']);
    }

    public function testGetOAuth2ProviderMatchesListEntry(): void
    {
        $list = $this->listOAuth2Providers();
        $this->assertSame(200, $list['headers']['status-code']);

        $byId = [];
        foreach ($list['body']['providers'] as $provider) {
            $byId[$provider['$id']] = $provider;
        }

        // Match GET against LIST for one provider per shape.
        foreach (['github', 'amazon', 'dropbox', 'gitlab', 'apple', 'oidc', 'microsoft'] as $providerId) {
            $get = $this->getOAuth2Provider($providerId);
            $this->assertSame(200, $get['headers']['status-code']);
            $this->assertArrayHasKey($providerId, $byId, "{$providerId} missing from list");
            $this->assertSame($byId[$providerId], $get['body']);
        }
    }

    public function testGetOAuth2ProviderUnsupported(): void
    {
        $response = $this->getOAuth2Provider('not-a-real-provider');

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_provider_unsupported', $response['body']['type']);
    }

    public function testGetOAuth2ProviderWithoutAuthentication(): void
    {
        $response = $this->getOAuth2Provider('github', authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Update plain provider (Amazon — clientId + clientSecret, no extra fields)
    // =========================================================================

    public function testUpdateOAuth2Plain(): void
    {
        $response = $this->updateOAuth2('amazon', [
            'clientId' => 'amzn1.application-oa2-client.test01',
            'clientSecret' => 'test-secret-01',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('amazon', $response['body']['$id']);
        $this->assertSame('amzn1.application-oa2-client.test01', $response['body']['clientId']);
        $this->assertSame(false, $response['body']['enabled']);
    }

    public function testUpdateOAuth2PlainEnable(): void
    {
        // Amazon has no verifyCredentials() hook, so enabling with arbitrary
        // credentials succeeds without making a real network call.
        $response = $this->updateOAuth2('amazon', [
            'clientId' => 'amzn1.application-oa2-client.test02',
            'clientSecret' => 'test-secret-02',
            'enabled' => true,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['enabled']);
    }

    public function testUpdateOAuth2PlainDisable(): void
    {
        $this->updateOAuth2('amazon', [
            'clientId' => 'amzn1.application-oa2-client.test03',
            'clientSecret' => 'test-secret-03',
            'enabled' => true,
        ]);

        $response = $this->updateOAuth2('amazon', [
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['enabled']);
        // Credentials persist across an enabled toggle.
        $this->assertSame('amzn1.application-oa2-client.test03', $response['body']['clientId']);
    }

    public function testUpdateOAuth2PlainPartial(): void
    {
        // Seed both credentials.
        $this->updateOAuth2('amazon', [
            'clientId' => 'seed-client-id',
            'clientSecret' => 'seed-secret',
            'enabled' => false,
        ]);

        // Patch only clientId.
        $response = $this->updateOAuth2('amazon', [
            'clientId' => 'updated-client-id',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('updated-client-id', $response['body']['clientId']);

        // Read back through GET to confirm the secret is still set internally
        // (write-only, so we cannot inspect the value, but enabling should still
        // succeed because the secret remains non-empty).
        $enable = $this->updateOAuth2('amazon', [
            'enabled' => true,
        ]);
        $this->assertSame(200, $enable['headers']['status-code']);
        $this->assertSame(true, $enable['body']['enabled']);
    }

    public function testUpdateOAuth2PlainEnableRequiresCredentials(): void
    {
        // Start from a clean state with no credentials.
        $this->updateOAuth2('amazon', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('amazon', [
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2PlainEnabledOmittedDoesNotThrow(): void
    {
        // With enabled omitted (null) and no credentials, the silent-validation
        // branch must not surface as an error.
        $this->updateOAuth2('amazon', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('amazon', [
            'clientId' => 'partial-only',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['enabled']);
        $this->assertSame('partial-only', $response['body']['clientId']);
    }

    public function testUpdateOAuth2PlainResponseModel(): void
    {
        $response = $this->updateOAuth2('amazon', [
            'clientId' => 'amzn1.application-oa2-client.modelCheck',
            'clientSecret' => 'model-check-secret',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('enabled', $response['body']);
        $this->assertArrayHasKey('clientId', $response['body']);
        $this->assertArrayHasKey('clientSecret', $response['body']);
    }

    public function testUpdateOAuth2WithoutAuthentication(): void
    {
        $response = $this->updateOAuth2('amazon', [
            'clientId' => 'no-auth',
            'clientSecret' => 'no-auth',
            'enabled' => false,
        ], authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateOAuth2UnknownProvider(): void
    {
        // Each Update endpoint is registered at a fixed `/oauth2/{providerId}`
        // path, so an unknown provider does not match any route → 404.
        $response = $this->updateOAuth2('not-a-real-provider', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
            'enabled' => false,
        ]);

        $this->assertSame(404, $response['headers']['status-code']);
    }

    public function testUpdateOAuth2InvalidEnabled(): void
    {
        $response = $this->updateOAuth2('amazon', [
            'enabled' => 'not-a-boolean',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    // =========================================================================
    // Update GitHub (verifyCredentials makes a real call to GitHub on enable)
    // =========================================================================

    public function testUpdateOAuth2GitHubInvalidCredentialsRejected(): void
    {
        // GitHub is the only provider with a real verifyCredentials() hook.
        // Enabling with bogus credentials must surface a 400 from the wrapping
        // exception, not silently succeed.
        $response = $this->updateOAuth2('github', [
            'clientId' => 'fake-client-id-' . \uniqid(),
            'clientSecret' => 'fake-client-secret',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        // Cleanup: ensure it's left disabled.
        $this->updateOAuth2('github', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2GitHubInvalidCredentialsSilentWhenNotEnabling(): void
    {
        // When `enabled` is omitted, verifyCredentials() failure is swallowed.
        // The provider remains disabled but the request succeeds.
        $response = $this->updateOAuth2('github', [
            'clientId' => 'still-fake-' . \uniqid(),
            'clientSecret' => 'still-fake-secret',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['enabled']);

        // Cleanup
        $this->updateOAuth2('github', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Apple (serviceId + keyId + teamId + p8File)
    // =========================================================================

    public function testUpdateOAuth2Apple(): void
    {
        $response = $this->updateOAuth2('apple', [
            'serviceId' => 'ip.appwrite.app.web',
            'keyId' => 'P4000000N8',
            'teamId' => 'D4000000R6',
            'p8File' => '-----BEGIN PRIVATE KEY-----TEST-----END PRIVATE KEY-----',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('apple', $response['body']['$id']);
        $this->assertSame('ip.appwrite.app.web', $response['body']['serviceId']);
        $this->assertSame('P4000000N8', $response['body']['keyId']);
        $this->assertSame('D4000000R6', $response['body']['teamId']);
        $this->assertSame(false, $response['body']['enabled']);

        // Cleanup
        $this->updateOAuth2('apple', [
            'serviceId' => '',
            'keyId' => '',
            'teamId' => '',
            'p8File' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2ApplePartial(): void
    {
        // Seed all four fields.
        $this->updateOAuth2('apple', [
            'serviceId' => 'ip.appwrite.app.seed',
            'keyId' => 'KEYSEED01',
            'teamId' => 'TEAMSEED01',
            'p8File' => '-----BEGIN PRIVATE KEY-----SEED-----END PRIVATE KEY-----',
            'enabled' => false,
        ]);

        // Patch only `keyId` — others must be preserved.
        $response = $this->updateOAuth2('apple', [
            'keyId' => 'KEYUPDATED',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('KEYUPDATED', $response['body']['keyId']);
        $this->assertSame('TEAMSEED01', $response['body']['teamId']);
        $this->assertSame('ip.appwrite.app.seed', $response['body']['serviceId']);

        // Cleanup
        $this->updateOAuth2('apple', [
            'serviceId' => '',
            'keyId' => '',
            'teamId' => '',
            'p8File' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2AppleResponseModel(): void
    {
        $response = $this->updateOAuth2('apple', [
            'serviceId' => 'ip.appwrite.app.shape',
            'keyId' => 'SHAPEKEY01',
            'teamId' => 'SHAPETEAM',
            'p8File' => '-----BEGIN PRIVATE KEY-----SHAPE-----END PRIVATE KEY-----',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('enabled', $response['body']);
        $this->assertArrayHasKey('serviceId', $response['body']);
        $this->assertArrayHasKey('keyId', $response['body']);
        $this->assertArrayHasKey('teamId', $response['body']);
        $this->assertArrayHasKey('p8File', $response['body']);
        // Apple has no clientId/clientSecret in the response model.
        $this->assertArrayNotHasKey('clientId', $response['body']);
        $this->assertArrayNotHasKey('clientSecret', $response['body']);

        // Cleanup
        $this->updateOAuth2('apple', [
            'serviceId' => '',
            'keyId' => '',
            'teamId' => '',
            'p8File' => '',
            'enabled' => false,
        ]);
    }

    public function testGetOAuth2AppleSecretsWriteOnly(): void
    {
        $this->updateOAuth2('apple', [
            'serviceId' => 'ip.appwrite.app.read',
            'keyId' => 'KEYREAD',
            'teamId' => 'TEAMREAD',
            'p8File' => '-----BEGIN PRIVATE KEY-----READ-----END PRIVATE KEY-----',
            'enabled' => false,
        ]);

        $response = $this->getOAuth2Provider('apple');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('ip.appwrite.app.read', $response['body']['serviceId']);
        // All three secret-bearing fields must be hidden on read.
        $this->assertSame('', $response['body']['keyId']);
        $this->assertSame('', $response['body']['teamId']);
        $this->assertSame('', $response['body']['p8File']);

        // Cleanup
        $this->updateOAuth2('apple', [
            'serviceId' => '',
            'keyId' => '',
            'teamId' => '',
            'p8File' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Auth0 (clientId + clientSecret + optional endpoint)
    // =========================================================================

    public function testUpdateOAuth2Auth0(): void
    {
        $response = $this->updateOAuth2('auth0', [
            'clientId' => 'OaOkIA000000000000000000005KLSYq',
            'clientSecret' => 'auth0-test-secret',
            'endpoint' => 'example.us.auth0.com',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('auth0', $response['body']['$id']);
        $this->assertSame('OaOkIA000000000000000000005KLSYq', $response['body']['clientId']);
        $this->assertSame('example.us.auth0.com', $response['body']['endpoint']);

        // Cleanup
        $this->updateOAuth2('auth0', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2Auth0PartialEndpoint(): void
    {
        // Seed clientSecret + endpoint.
        $this->updateOAuth2('auth0', [
            'clientId' => 'auth0-seed-client',
            'clientSecret' => 'auth0-seed-secret',
            'endpoint' => 'seed.us.auth0.com',
            'enabled' => false,
        ]);

        // Update only endpoint.
        $response = $this->updateOAuth2('auth0', [
            'endpoint' => 'updated.us.auth0.com',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('updated.us.auth0.com', $response['body']['endpoint']);
        // clientId is unchanged on top-level provider state.
        $this->assertSame('auth0-seed-client', $response['body']['clientId']);

        // Cleanup
        $this->updateOAuth2('auth0', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Authentik (clientId + clientSecret + REQUIRED endpoint)
    // =========================================================================

    public function testUpdateOAuth2AuthentikRequiresEndpoint(): void
    {
        // The `endpoint` param is required (Text(min=1)); omitting → 400.
        $response = $this->updateOAuth2('authentik', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateOAuth2Authentik(): void
    {
        $response = $this->updateOAuth2('authentik', [
            'clientId' => 'dTKOPa0000000000000000000000000000e7G8hv',
            'clientSecret' => 'authentik-secret',
            'endpoint' => 'example.authentik.com',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('authentik', $response['body']['$id']);
        $this->assertSame('dTKOPa0000000000000000000000000000e7G8hv', $response['body']['clientId']);
        $this->assertSame('example.authentik.com', $response['body']['endpoint']);

        // Cleanup
        $this->updateOAuth2('authentik', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => 'cleanup.authentik.com',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Microsoft (applicationId + applicationSecret + REQUIRED tenant)
    // =========================================================================

    public function testUpdateOAuth2MicrosoftRequiresTenant(): void
    {
        $response = $this->updateOAuth2('microsoft', [
            'applicationId' => 'whatever',
            'applicationSecret' => 'whatever',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateOAuth2Microsoft(): void
    {
        $response = $this->updateOAuth2('microsoft', [
            'applicationId' => '00001111-aaaa-2222-bbbb-3333cccc4444',
            'applicationSecret' => 'A1bC2dE3fH4iJ5kL6mN7oP8qR9sT0u',
            'tenant' => 'common',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('microsoft', $response['body']['$id']);
        $this->assertSame('00001111-aaaa-2222-bbbb-3333cccc4444', $response['body']['applicationId']);
        $this->assertSame('common', $response['body']['tenant']);
        // Custom param names: applicationId/applicationSecret, not clientId/clientSecret.
        $this->assertArrayNotHasKey('clientId', $response['body']);
        $this->assertArrayNotHasKey('clientSecret', $response['body']);

        // Cleanup
        $this->updateOAuth2('microsoft', [
            'applicationId' => '',
            'applicationSecret' => '',
            'tenant' => 'common',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2MicrosoftPartialPreservesSecret(): void
    {
        // Seed full credentials.
        $this->updateOAuth2('microsoft', [
            'applicationId' => 'seed-app-id',
            'applicationSecret' => 'seed-app-secret',
            'tenant' => 'common',
            'enabled' => false,
        ]);

        // Patch with only `tenant` (it's required on every call) and a new
        // applicationId, leaving applicationSecret omitted. The stored secret
        // must not be wiped.
        $response = $this->updateOAuth2('microsoft', [
            'applicationId' => 'updated-app-id',
            'tenant' => 'organizations',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('updated-app-id', $response['body']['applicationId']);
        $this->assertSame('organizations', $response['body']['tenant']);

        // Cleanup
        $this->updateOAuth2('microsoft', [
            'applicationId' => '',
            'applicationSecret' => '',
            'tenant' => 'common',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Gitlab (applicationId + secret + optional endpoint, custom names)
    // =========================================================================

    public function testUpdateOAuth2Gitlab(): void
    {
        $response = $this->updateOAuth2('gitlab', [
            'applicationId' => 'd41ffe0000000000000000000000000000000000000000000000000000d5e252',
            'secret' => 'gloas-838cfa00',
            'endpoint' => 'https://gitlab.example.com',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('gitlab', $response['body']['$id']);
        $this->assertSame('d41ffe0000000000000000000000000000000000000000000000000000d5e252', $response['body']['applicationId']);
        $this->assertSame('https://gitlab.example.com', $response['body']['endpoint']);
        // Custom names — the response model exposes `applicationId`/`secret`.
        $this->assertArrayNotHasKey('clientId', $response['body']);
        $this->assertArrayNotHasKey('clientSecret', $response['body']);

        // Cleanup
        $this->updateOAuth2('gitlab', [
            'applicationId' => '',
            'secret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2GitlabInvalidEndpoint(): void
    {
        $response = $this->updateOAuth2('gitlab', [
            'applicationId' => 'whatever',
            'secret' => 'whatever',
            'endpoint' => 'not a url',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateOAuth2GitlabPartialEndpoint(): void
    {
        $this->updateOAuth2('gitlab', [
            'applicationId' => 'gitlab-seed-app',
            'secret' => 'gitlab-seed-secret',
            'endpoint' => 'https://seed.gitlab.com',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('gitlab', [
            'endpoint' => 'https://updated.gitlab.com',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('https://updated.gitlab.com', $response['body']['endpoint']);
        $this->assertSame('gitlab-seed-app', $response['body']['applicationId']);

        // Cleanup
        $this->updateOAuth2('gitlab', [
            'applicationId' => '',
            'secret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update OIDC (clientId + secret + wellKnownURL or 3 discovery URLs)
    // =========================================================================

    public function testUpdateOAuth2OidcWithWellKnown(): void
    {
        $response = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-client',
            'clientSecret' => 'oidc-secret',
            'wellKnownURL' => 'https://idp.example.com/.well-known/openid-configuration',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('https://idp.example.com/.well-known/openid-configuration', $response['body']['wellKnownURL']);
        $this->assertArrayHasKey('authorizationURL', $response['body']);
        $this->assertArrayHasKey('tokenUrl', $response['body']);
        $this->assertArrayHasKey('userInfoUrl', $response['body']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcWithDiscoveryURLs(): void
    {
        $response = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-discovery',
            'clientSecret' => 'oidc-discovery-secret',
            'authorizationURL' => 'https://idp.example.com/oauth2/authorize',
            'tokenUrl' => 'https://idp.example.com/oauth2/token',
            'userInfoUrl' => 'https://idp.example.com/oauth2/userinfo',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('https://idp.example.com/oauth2/authorize', $response['body']['authorizationURL']);
        $this->assertSame('https://idp.example.com/oauth2/token', $response['body']['tokenUrl']);
        $this->assertSame('https://idp.example.com/oauth2/userinfo', $response['body']['userInfoUrl']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcEnableMissingURLs(): void
    {
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-no-urls',
            'clientSecret' => 'oidc-no-urls',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcEnablePartialDiscoveryFails(): void
    {
        // Only authorization+token, missing userInfo — must fail to enable.
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-partial',
            'clientSecret' => 'oidc-partial-secret',
            'authorizationURL' => 'https://idp.example.com/oauth2/authorize',
            'tokenUrl' => 'https://idp.example.com/oauth2/token',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Okta (clientId + clientSecret + optional domain/authServer)
    // =========================================================================

    public function testUpdateOAuth2Okta(): void
    {
        $response = $this->updateOAuth2('okta', [
            'clientId' => '0oa00000000000000698',
            'clientSecret' => 'okta-secret',
            'domain' => 'trial-6400025.okta.com',
            'authorizationServerId' => 'aus000000000000000h7z',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('okta', $response['body']['$id']);
        $this->assertSame('0oa00000000000000698', $response['body']['clientId']);
        $this->assertSame('trial-6400025.okta.com', $response['body']['domain']);
        $this->assertSame('aus000000000000000h7z', $response['body']['authorizationServerId']);

        // Cleanup
        $this->updateOAuth2('okta', [
            'clientId' => '',
            'clientSecret' => '',
            'domain' => '',
            'authorizationServerId' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OktaInvalidDomain(): void
    {
        $response = $this->updateOAuth2('okta', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
            'domain' => 'https://trial-6400025.okta.com/',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateOAuth2OktaEnableRequiresDomain(): void
    {
        $this->updateOAuth2('okta', [
            'clientId' => '',
            'clientSecret' => '',
            'domain' => '',
            'authorizationServerId' => '',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('okta', [
            'clientId' => 'okta-no-domain',
            'clientSecret' => 'okta-no-domain-secret',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        // Cleanup
        $this->updateOAuth2('okta', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Dropbox (custom param names: appKey + appSecret)
    // =========================================================================

    public function testUpdateOAuth2DropboxFieldNames(): void
    {
        $response = $this->updateOAuth2('dropbox', [
            'appKey' => 'jl000000000009t',
            'appSecret' => 'g200000000000vw',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('dropbox', $response['body']['$id']);
        $this->assertSame('jl000000000009t', $response['body']['appKey']);
        $this->assertArrayHasKey('appSecret', $response['body']);
        $this->assertArrayNotHasKey('clientId', $response['body']);
        $this->assertArrayNotHasKey('clientSecret', $response['body']);

        // GET enforces write-only on the secret regardless of the custom name.
        $get = $this->getOAuth2Provider('dropbox');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('jl000000000009t', $get['body']['appKey']);
        $this->assertSame('', $get['body']['appSecret']);

        // Cleanup
        $this->updateOAuth2('dropbox', [
            'appKey' => '',
            'appSecret' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Paypal Sandbox (inherits from Paypal — independent provider ID)
    // =========================================================================

    public function testUpdateOAuth2PaypalSandbox(): void
    {
        $response = $this->updateOAuth2('paypalSandbox', [
            'clientId' => 'paypal-sandbox-client',
            'clientSecret' => 'paypal-sandbox-secret',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('paypalSandbox', $response['body']['$id']);
        $this->assertSame('paypal-sandbox-client', $response['body']['clientId']);

        // Sandbox is independent of the regular paypal entry.
        $regular = $this->getOAuth2Provider('paypal');
        $this->assertSame(200, $regular['headers']['status-code']);
        $this->assertSame('paypal', $regular['body']['$id']);
        $this->assertNotSame('paypal-sandbox-client', $regular['body']['clientId']);

        // Cleanup
        $this->updateOAuth2('paypalSandbox', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<string, mixed> $params
     */
    protected function updateOAuth2(string $provider, array $params, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(
            Client::METHOD_PATCH,
            '/project/oauth2/' . $provider,
            $headers,
            $params,
        );
    }

    protected function getOAuth2Provider(string $provider, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(
            Client::METHOD_GET,
            '/project/oauth2/' . $provider,
            $headers,
        );
    }

    protected function listOAuth2Providers(bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(
            Client::METHOD_GET,
            '/project/oauth2',
            $headers,
        );
    }
}
