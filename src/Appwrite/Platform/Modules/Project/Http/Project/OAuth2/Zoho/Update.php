<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Zoho;

use Appwrite\Auth\OAuth2\Zoho;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'zoho';
    }

    public static function getProviderClass(): string
    {
        return Zoho::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Zoho';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Zoho';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_ZOHO;
    }

    public static function getClientIdDescription(): string
    {
        return '\'Client ID\' of Zoho OAuth2 app. For example: 1000.83C178000000000000000000RPNX0B';
    }

    public static function getClientSecretDescription(): string
    {
        return '\'Client Secret\' of Zoho OAuth2 app. For example: fb5cac000000000000000000000000000000a68f6e';
    }
}
