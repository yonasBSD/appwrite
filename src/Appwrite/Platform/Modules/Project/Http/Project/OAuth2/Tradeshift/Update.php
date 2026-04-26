<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Tradeshift;

use Appwrite\Auth\OAuth2\Tradeshift;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'tradeshift';
    }

    public static function getProviderClass(): string
    {
        return Tradeshift::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Tradeshift';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Tradeshift';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_TRADESHIFT;
    }

    public static function getClientIdParamName(): string
    {
        return 'oauth2ClientId';
    }

    public static function getClientSecretParamName(): string
    {
        return 'oauth2ClientSecret';
    }

    public static function getClientIdDescription(): string
    {
        return '\'Oauth2 Client ID\' of ' . static::getProviderLabel() . ' OAuth2 app. For example: appwrite-tes00000.0000000000est-app';
    }

    public static function getClientSecretDescription(): string
    {
        return '\'Oauth2 Client secret\' of ' . static::getProviderLabel() . ' OAuth2 app. For example: 7cb52700-0000-0000-0000-000000ca5b83';
    }
}
