<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listProjectOAuth2';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/oauth2')
            ->desc('List project OAuth2 providers')
            ->groups(['api', 'project'])
            ->label('scope', 'oauth2.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'oauth2',
                name: 'listOAuth2Providers',
                description: <<<EOT
                Get a list of all OAuth2 providers supported by the server, along with the project's configuration for each. The `secret` is write-only and is always returned empty.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_AUTH_PROVIDER_LIST,
                    )
                ]
            ))
            ->inject('response')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        Document $project,
    ): void {
        $providers = Config::getParam('oAuthProviders', []);
        $providerValues = $project->getAttribute('oAuthProviders', []);

        $projectProviders = [];
        foreach ($providers as $key => $provider) {
            if (!($provider['enabled'] ?? false)) {
                // Disabled by Appwrite configuration, exclude from response
                continue;
            }

            $projectProviders[] = new Document([
                'key' => $key,
                'name' => $provider['name'] ?? '',
                'appId' => $providerValues[$key . 'Appid'] ?? '',
                'secret' => '',
                'enabled' => $providerValues[$key . 'Enabled'] ?? false,
            ]);
        }

        $response->dynamic(new Document([
            'total' => \count($projectProviders),
            'platforms' => $projectProviders,
        ]), Response::MODEL_AUTH_PROVIDER_LIST);
    }
}
