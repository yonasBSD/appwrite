<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Bitly;

use Appwrite\Auth\OAuth2\Bitly;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'bitly';
    }

    public static function getProviderClass(): string
    {
        return Bitly::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Bitly';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Bitly';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_BITLY;
    }

    public static function getClientIdDescription(): string
    {
        return 'Client ID of Bitly OAuth2 app. For example: d95151000000000000000000000000000067af9b';
    }

    public static function getClientSecretDescription(): string
    {
        return 'Client secret of Bitly OAuth2 app. For example: a13e250000000000000000000000000000d73095';
    }
}
