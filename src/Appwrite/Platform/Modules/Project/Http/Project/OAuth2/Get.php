<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getProjectOAuth2';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/oauth2/:provider')
            ->desc('Get project OAuth2 provider')
            ->groups(['api', 'project'])
            ->label('scope', 'oauth2.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'oauth2',
                name: 'getOAuth2Provider',
                description: <<<EOT
                Get a single OAuth2 provider configuration. The `secret` is write-only and is always returned empty.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_AUTH_PROVIDER,
                    )
                ]
            ))
            ->param('provider', '', new Text(128), 'OAuth2 provider key. For example: github, google, apple.')
            ->inject('response')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $provider,
        Response $response,
        Document $project,
    ): void {
        $providers = Config::getParam('oAuthProviders', []);
        if (!\array_key_exists($provider, $providers) || !($providers[$provider]['enabled'] ?? false)) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        $providerValues = $project->getAttribute('oAuthProviders', []);

        $response->dynamic(new Document([
            'key' => $provider,
            'name' => $providers[$provider]['name'] ?? '',
            'appId' => $providerValues[$provider . 'Appid'] ?? '',
            'secret' => '',
            'enabled' => $providerValues[$provider . 'Enabled'] ?? false,
        ]), Response::MODEL_AUTH_PROVIDER);
    }
}
