<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\TradeshiftBox;

use Appwrite\Auth\OAuth2\TradeshiftBox;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'tradeshiftBox';
    }

    public static function getProviderClass(): string
    {
        return TradeshiftBox::class;
    }

    public static function getProviderLabel(): string
    {
        return 'TradeshiftBox';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2TradeshiftBox';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_TRADESHIFT_BOX;
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
        return 'Oauth2 Client ID of Tradeshift Sandbox OAuth2 app. For example: appwrite-test-org.appwrite-test-app';
    }

    public static function getClientSecretDescription(): string
    {
        return 'Oauth2 Client secret of Tradeshift Sandbox OAuth2 app. For example: 7cb52700-0000-0000-0000-000000ca5b83';
    }
}
