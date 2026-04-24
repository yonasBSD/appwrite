<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Discord;

use Appwrite\Auth\OAuth2\Discord;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectOAuth2Discord';
    }

    public static function getProviderId(): string
    {
        return 'discord';
    }

    /**
     * @return class-string
     */
    public static function getProviderClass(): string
    {
        return Discord::class;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/oauth2/discord')
            ->desc('Update project OAuth2 Discord')
            ->groups(['api', 'project'])
            ->label('scope', 'oauth2.write')
            ->label('event', 'oauth2.discord.update')
            ->label('audits.event', 'project.oauth2.discord.update')
            ->label('audits.resource', 'project.oauth2/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'oauth2',
                name: 'updateOAuth2Discord',
                description: <<<EOT
                Update the project OAuth2 Discord configuration.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_OAUTH2_DISCORD,
                    )
                ],
            ))
            ->param('clientId', null, new Nullable(new Text(256, 0)), 'Client ID of Discord OAuth2 app. For example: 950722000000343754', optional: true)
            ->param('clientSecret', null, new Nullable(new Text(512, 0)), 'Client Secret of Discord OAuth2 app. For example: YmPXnM000000000000000000002zFg5D', optional: true)
            ->param('enabled', null, new Nullable(new Boolean()), 'OAuth2 sign-in method status. Set to true to enable new session creation. Setting to true will trigger end-to-end credentials validation, and will throw if the credentials are invalid.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        ?string $clientId,
        ?string $clientSecret,
        ?bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization
    ): void {
        $providerId = self::getProviderId();
        if(!(\in_array($providerId, \array_keys(Config::getParam('oAuthProviders'))))) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Provider ' . $providerId . ' is not supported by server configuration.');
        }

        $oAuthProviders = $project->getAttribute('oAuthProviders', []);

        $appIdKey = $providerId . 'Appid';
        $appSecretKey = $providerId . 'Secret';
        $enabledKey = $providerId . 'Enabled';

        if (!\is_null($clientId)) {
            $oAuthProviders[$appIdKey] = $clientId;
        }

        if (!\is_null($clientSecret)) {
            $oAuthProviders[$appSecretKey] = $clientSecret;
        }

        if (!\is_null($enabled)) {
            $oAuthProviders[$enabledKey] = $enabled;
        }

        if($enabled === true || \is_null($enabled)) {
            try {
                if(empty($oAuthProviders[$appIdKey]) || empty($oAuthProviders[$appSecretKey])) {
                    throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Client ID and Client Secret are required when enabling OAuth2 provider.');
                }

                $providerClass = self::getProviderClass();
                $providerInstance = new $providerClass(appId: $oAuthProviders[$appIdKey], appSecret: $oAuthProviders[$appSecretKey], callback: '', state: [], scopes: []);

                $providerInstance->verifyCredentials();

                $oAuthProviders[$enabledKey] = true;
            } catch(\Throwable $err) {
                if($enabled === true) {
                    throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Could not enable OAuth2 provider: ' . $err->getMessage());
                }
            }
        }

        $updates = new Document([
            'oAuthProviders' => $oAuthProviders
        ]);

        $project = $authorization->skip(fn() => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));

        $response->dynamic(new Document([
            '$id' => $providerId,
            'enabled' => $oAuthProviders[$enabledKey] ?? false,
            'clientId' => $oAuthProviders[$appIdKey] ?? '',
            'clientSecret' => $oAuthProviders[$appSecretKey] ?? '',
        ]), Response::MODEL_OAUTH2_DISCORD);
    }
}
