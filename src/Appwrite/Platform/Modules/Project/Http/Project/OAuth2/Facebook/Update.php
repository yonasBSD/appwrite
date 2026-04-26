<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Facebook;

use Appwrite\Auth\OAuth2\Facebook;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'facebook';
    }

    public static function getProviderClass(): string
    {
        return Facebook::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Facebook';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Facebook';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_FACEBOOK;
    }

    public static function getClientIdParamName(): string
    {
        return 'appId';
    }

    public static function getClientSecretParamName(): string
    {
        return 'appSecret';
    }

    public static function getClientIdDescription(): string
    {
        return '\'App ID\' of Facebook OAuth2 app. For example: 260600000007694';
    }

    public static function getClientSecretDescription(): string
    {
        return '\'App secret\' of Facebook OAuth2 app. For example: 2d0b2800000000000000000000d38af4';
    }
}
