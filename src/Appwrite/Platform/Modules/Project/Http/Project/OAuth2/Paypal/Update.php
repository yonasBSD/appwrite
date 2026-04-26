<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Paypal;

use Appwrite\Auth\OAuth2\Paypal;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'paypal';
    }

    public static function getProviderClass(): string
    {
        return Paypal::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Paypal';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Paypal';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_PAYPAL;
    }

    public static function getClientSecretParamName(): string
    {
        return 'secretKey';
    }

    public static function getClientIdDescription(): string
    {
        return '\'Client ID\' of ' . static::getProviderLabel() . ' OAuth2 app. For example: AdhIEG7-000000000000-0000000000000000000000000000000-0000000000000000000000-2pyB';
    }

    public static function getClientSecretDescription(): string
    {
        return '\'Secret key 1\', or \'Secret key 2\', of ' . static::getProviderLabel() . ' OAuth2 app. For example: EH8KCXtew--000000000000000000000000000000000000000_C-1_5UP_000000000000000CB7KDp';
    }
}
