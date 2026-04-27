<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2;

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
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

abstract class Base extends Action
{
    use HTTP;

    /**
     * Provider ID used in paths, database keys and event labels.
     *
     * @return string e.g. 'github', 'discord', 'figma'
     */
    abstract public static function getProviderId(): string;

    /**
     * Provider OAuth2 implementation class. Must implement verifyCredentials().
     *
     * @return class-string e.g. Github::class
     */
    abstract public static function getProviderClass(): string;

    /**
     * Provider display label used in descriptions, SDK method name and action name.
     *
     * @return string e.g. 'GitHub', 'Discord', 'Figma'
     */
    abstract public static function getProviderLabel(): string;

    /**
     * Response model constant for this provider.
     *
     * @return string e.g. Response::MODEL_OAUTH2_GITHUB
     */
    abstract public static function getResponseModel(): string;

    /**
     * Description of the clientId param, including an example value.
     *
     * @return string
     */
    abstract public static function getClientIdDescription(): string;

    /**
     * Description of the clientSecret param, including an example value.
     *
     * @return string
     */
    abstract public static function getClientSecretDescription(): string;

    /**
     * Public-facing name of the clientId param. Some providers use a different
     * terminology (e.g. Dropbox calls it "App key"), so the param name and the
     * corresponding response field can be customized by overriding this method.
     *
     * @return string e.g. 'clientId' (default), 'appKey'
     */
    public static function getClientIdParamName(): string
    {
        return 'clientId';
    }

    /**
     * Public-facing name of the clientSecret param. Some providers use a
     * different terminology (e.g. Dropbox calls it "App secret"), so the param
     * name and the corresponding response field can be customized by
     * overriding this method.
     *
     * @return string e.g. 'clientSecret' (default), 'appSecret'
     */
    public static function getClientSecretParamName(): string
    {
        return 'clientSecret';
    }

    /**
     * SDK method name exposed to clients.
     *
     * @return string e.g. 'updateOAuth2GitHub'
     */
    abstract public static function getProviderSDKMethod(): string;

    public static function getName()
    {
        return 'updateProjectOAuth2' . static::getProviderLabel();
    }

    public function __construct()
    {
        $providerId = static::getProviderId();
        $providerLabel = static::getProviderLabel();

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/oauth2/' . $providerId)
            ->desc('Update project OAuth2 ' . $providerLabel)
            ->groups(['api', 'project'])
            ->label('scope', 'oauth2.write')
            ->label('event', 'oauth2.' . $providerId . '.update')
            ->label('audits.event', 'project.oauth2.' . $providerId . '.update')
            ->label('audits.resource', 'project.oauth2/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'oauth2',
                name: static::getProviderSDKMethod(),
                description: 'Update the project OAuth2 ' . $providerLabel . ' configuration.',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: static::getResponseModel(),
                    )
                ],
            ))
            ->param(static::getClientIdParamName(), null, new Nullable(new Text(256, 0)), static::getClientIdDescription(), optional: true)
            ->param(static::getClientSecretParamName(), null, new Nullable(new Text(512, 0)), static::getClientSecretDescription(), optional: true)
            ->param('enabled', null, new Nullable(new Boolean()), 'OAuth2 sign-in method status. Set to true to enable new session creation. Setting to true will trigger end-to-end credentials validation, and will throw if the credentials are invalid.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * Registry of provider ID -> Update action class. Mirrors the OAuth2
     * actions registered in Project\Services\Http. Used by the Get and XList
     * read endpoints to dispatch per-provider response shaping.
     *
     * @return array<string, class-string<Base>>
     */
    public static function getProviderActions(): array
    {
        return [
            'github' => GitHub\Update::class,
            'discord' => Discord\Update::class,
            'figma' => Figma\Update::class,
            'dropbox' => Dropbox\Update::class,
            'dailymotion' => Dailymotion\Update::class,
            'bitbucket' => Bitbucket\Update::class,
            'bitly' => Bitly\Update::class,
            'box' => Box\Update::class,
            'autodesk' => Autodesk\Update::class,
            'google' => Google\Update::class,
            'zoom' => Zoom\Update::class,
            'zoho' => Zoho\Update::class,
            'yandex' => Yandex\Update::class,
            'x' => X\Update::class,
            'wordpress' => WordPress\Update::class,
            'twitch' => Twitch\Update::class,
            'stripe' => Stripe\Update::class,
            'spotify' => Spotify\Update::class,
            'slack' => Slack\Update::class,
            'podio' => Podio\Update::class,
            'notion' => Notion\Update::class,
            'salesforce' => Salesforce\Update::class,
            'yahoo' => Yahoo\Update::class,
            'linkedin' => Linkedin\Update::class,
            'disqus' => Disqus\Update::class,
            'amazon' => Amazon\Update::class,
            'etsy' => Etsy\Update::class,
            'facebook' => Facebook\Update::class,
            'tradeshift' => Tradeshift\Update::class,
            'tradeshiftSandbox' => TradeshiftSandbox\Update::class,
            'paypal' => Paypal\Update::class,
            'paypalSandbox' => PaypalSandbox\Update::class,
            'gitlab' => Gitlab\Update::class,
            'authentik' => Authentik\Update::class,
            'auth0' => Auth0\Update::class,
            'oidc' => Oidc\Update::class,
            'okta' => Okta\Update::class,
            'kick' => Kick\Update::class,
            'apple' => Apple\Update::class,
        ];
    }

    /**
     * Build the read-only response document for this provider, with credential
     * fields zeroed out (write-only). Default implementation handles providers
     * that store a plain client ID + client secret. Special providers (Apple,
     * Gitlab, Auth0, Authentik, Oidc, Okta) override to expose their
     * non-secret extras (endpoint, domain, discovery URLs, ...) decoded from
     * the JSON-encoded secret blob.
     */
    public function buildReadResponse(Document $project): Document
    {
        $providerId = static::getProviderId();
        $oAuthProviders = $project->getAttribute('oAuthProviders', []);

        return new Document([
            '$id' => $providerId,
            'enabled' => $oAuthProviders[$providerId . 'Enabled'] ?? false,
            static::getClientIdParamName() => $oAuthProviders[$providerId . 'Appid'] ?? '',
            static::getClientSecretParamName() => '',
        ]);
    }

    /**
     * Decode the JSON-encoded secret blob stored under `{providerId}Secret`.
     * Returns an empty array when the value is empty or not valid JSON.
     */
    protected function decodeStoredSecret(Document $project): array
    {
        $providerId = static::getProviderId();
        $stored = $project->getAttribute('oAuthProviders', [])[$providerId . 'Secret'] ?? '';

        if (empty($stored)) {
            return [];
        }

        $decoded = \json_decode($stored, true);
        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Apply the provided credential changes to the project's oAuthProviders map,
     * run the optional credential verification hook, persist the project, and
     * return the updated project document.
     *
     * Providers that need to serialize multiple values into a single secret
     * (e.g. GitLab, which stores `{clientSecret, endpoint}` as JSON) should
     * encode those values into `$clientSecret` before calling this method.
     */
    protected function persistCredentials(
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
        ?string $clientId,
        ?string $clientSecret,
        ?bool $enabled
    ): Document {
        $providerId = static::getProviderId();
        if (!(\in_array($providerId, \array_keys(Config::getParam('oAuthProviders'))))) {
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

        if ($enabled === true || \is_null($enabled)) {
            try {
                if (empty($oAuthProviders[$appIdKey]) || empty($oAuthProviders[$appSecretKey])) {
                    throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Client ID and Client Secret are required when enabling OAuth2 provider.');
                }

                $providerClass = static::getProviderClass();
                $providerInstance = new $providerClass(appId: $oAuthProviders[$appIdKey], appSecret: $oAuthProviders[$appSecretKey], callback: '', state: [], scopes: []);

                // E2E integration check
                if (\method_exists($providerInstance, 'verifyCredentials')) {
                    $providerInstance->verifyCredentials();
                }

                $oAuthProviders[$enabledKey] = true;
            } catch (\Throwable $err) {
                if ($enabled === true) {
                    throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Could not enable OAuth2 provider: ' . $err->getMessage());
                }
            }
        }

        $updates = new Document([
            'oAuthProviders' => $oAuthProviders
        ]);

        return $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));
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
        $project = $this->persistCredentials($project, $dbForPlatform, $authorization, $clientId, $clientSecret, $enabled);

        $providerId = static::getProviderId();
        $oAuthProviders = $project->getAttribute('oAuthProviders', []);

        $response->dynamic(new Document([
            '$id' => $providerId,
            'enabled' => $oAuthProviders[$providerId . 'Enabled'] ?? false,
            static::getClientIdParamName() => $oAuthProviders[$providerId . 'Appid'] ?? '',
            static::getClientSecretParamName() => $oAuthProviders[$providerId . 'Secret'] ?? '',
        ]), static::getResponseModel());
    }
}
