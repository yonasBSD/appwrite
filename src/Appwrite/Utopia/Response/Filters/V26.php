<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;
use Utopia\Config\Config;

// Convert 1.9.5 Data format to 1.9.4 format
class V26 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            Response::MODEL_PROJECT => $this->parseProject($content, $this->rawContent),
            Response::MODEL_PROJECT_LIST => $this->handleList($content, 'projects', function ($item) {
                $projectId = $item['$id'] ?? '';

                $rawProjects = $this->rawContent['projects'] ?? [];
                $rawProject = null;
                foreach ($rawProjects as $rawItem) {
                    if ($rawItem['$id'] === $projectId) {
                        $rawProject = $rawItem;
                        break;
                    }
                }

                return $this->parseProject($item, $rawProject);
            }),
            default => $content,
        };
    }

    private function parseProject(array $content, array $raw): array
    {
        $this->expandAuthMethods($content);
        $this->expandServices($content);
        $this->expandProtocols($content);

        unset($content['authMethods'], $content['services'], $content['protocols']);

        $auths = $raw['auths'] ?? [];
        $content['authLimit'] = $auths['limit'] ?? 0;
        $content['authDuration'] = $auths['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        $content['authSessionsLimit'] = $auths['maxSessions'] ?? 0;
        $content['authPasswordHistory'] = $auths['passwordHistory'] ?? 0;
        $content['authPasswordDictionary'] = $auths['passwordDictionary'] ?? false;
        $content['authPersonalDataCheck'] = $auths['personalDataCheck'] ?? false;
        $content['authDisposableEmails'] = $auths['disposableEmails'] ?? false;
        $content['authCanonicalEmails'] = $auths['canonicalEmails'] ?? false;
        $content['authFreeEmails'] = $auths['freeEmails'] ?? false;
        $content['authMockNumbers'] = $auths['mockNumbers'] ?? [];
        $content['authSessionAlerts'] = $auths['sessionAlerts'] ?? false;
        $content['authMembershipsUserName'] = $auths['membershipsUserName'] ?? false;
        $content['authMembershipsUserEmail'] = $auths['membershipsUserEmail'] ?? false;
        $content['authMembershipsMfa'] = $auths['membershipsMfa'] ?? false;
        $content['authMembershipsUserId'] = $auths['membershipsUserId'] ?? false;
        $content['authMembershipsUserPhone'] = $auths['membershipsUserPhone'] ?? false;
        $content['authInvalidateSessions'] = $auths['invalidateSessions'] ?? false;

        $content['description'] = $raw['description'] ?? '';
        $content['logo'] = $raw['logo'] ?? '';
        $content['url'] = $raw['url'] ?? '';
        $content['legalName'] = $raw['legalName'] ?? '';
        $content['legalCountry'] = $raw['legalCountry'] ?? '';
        $content['legalState'] = $raw['legalState'] ?? '';
        $content['legalCity'] = $raw['legalCity'] ?? '';
        $content['legalAddress'] = $raw['legalAddress'] ?? '';
        $content['legalTaxId'] = $raw['legalTaxId'] ?? '';

        $content['oAuthProviders'] = $this->expandOAuthProviders();
        $content['platforms'] = $raw['platforms'] ?? [];
        $content['webhooks'] = $raw['webhooks'] ?? [];
        $content['keys'] = $raw['keys'] ?? [];

        return $content;
    }

    private function expandAuthMethods(array &$content): void
    {
        $authMethods = [];
        foreach ($content['authMethods'] ?? [] as $method) {
            $authMethods[$method['$id'] ?? ''] = $method['enabled'] ?? true;
        }

        foreach (Config::getParam('auth', []) as $id => $method) {
            $key = $method['key'] ?? '';
            $content['auth' . ucfirst($key)] = $authMethods[$id] ?? true;
        }
    }

    private function expandServices(array &$content): void
    {
        $services = [];
        foreach ($content['services'] ?? [] as $service) {
            $services[$service['$id'] ?? ''] = $service['enabled'] ?? true;
        }

        foreach (Config::getParam('services', []) as $id => $service) {
            if (!($service['optional'] ?? false)) {
                continue;
            }
            $key = $service['key'] ?? '';
            $content['serviceStatusFor' . ucfirst($key)] = $services[$id] ?? true;
        }
    }

    private function expandProtocols(array &$content): void
    {
        $protocols = [];
        foreach ($content['protocols'] ?? [] as $protocol) {
            $protocols[$protocol['$id'] ?? ''] = $protocol['enabled'] ?? true;
        }

        foreach (Config::getParam('protocols', []) as $id => $api) {
            $key = $api['key'] ?? '';
            $content['protocolStatusFor' . ucfirst($key)] = $protocols[$id] ?? true;
        }
    }

    private function expandOAuthProviders(): array
    {
        $providers = Config::getParam('oAuthProviders', []);
        $providerValues = $this->rawContent['oAuthProviders'] ?? [];
        $projectProviders = [];

        foreach ($providers as $key => $provider) {
            if (!($provider['enabled'] ?? false)) {
                continue;
            }

            $projectProviders[] = [
                'key' => $key,
                'name' => $provider['name'] ?? '',
                'appId' => $providerValues[$key . 'Appid'] ?? '',
                'secret' => '',
                'enabled' => $providerValues[$key . 'Enabled'] ?? false,
            ];
        }

        return $projectProviders;
    }
}
