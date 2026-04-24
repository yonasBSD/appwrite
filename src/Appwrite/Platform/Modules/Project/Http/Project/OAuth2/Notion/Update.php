<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Notion;

use Appwrite\Auth\OAuth2\Notion;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'notion';
    }

    public static function getProviderClass(): string
    {
        return Notion::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Notion';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_NOTION;
    }

    public static function getClientIdParamName(): string
    {
        return 'oauthClientId';
    }

    public static function getClientSecretParamName(): string
    {
        return 'oauthClientSecret';
    }

    public static function getClientIdDescription(): string
    {
        return 'OAuth Client ID of Notion OAuth2 app. For example: 341d8700-0000-0000-0000-000000446ee3';
    }

    public static function getClientSecretDescription(): string
    {
        return 'OAuth Client Secret of Notion OAuth2 app. For example: secret_dLUr4b000000000000000000000000000000lFHAa9';
    }
}
