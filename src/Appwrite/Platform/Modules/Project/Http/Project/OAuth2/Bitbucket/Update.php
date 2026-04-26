<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Bitbucket;

use Appwrite\Auth\OAuth2\Bitbucket;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'bitbucket';
    }

    public static function getProviderClass(): string
    {
        return Bitbucket::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Bitbucket';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Bitbucket';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_BITBUCKET;
    }

    public static function getClientIdParamName(): string
    {
        return 'key';
    }

    public static function getClientSecretParamName(): string
    {
        return 'secret';
    }

    public static function getClientIdDescription(): string
    {
        return '\'Key\' of Bitbucket OAuth2 app. For example: Knt70000000000ByRc';
    }

    public static function getClientSecretDescription(): string
    {
        return '\'Secret\' of Bitbucket OAuth2 app. For example: NMfLZJ00000000000000000000TLQdDx';
    }
}
