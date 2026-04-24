<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\WordPress;

use Appwrite\Auth\OAuth2\WordPress;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'wordpress';
    }

    public static function getProviderClass(): string
    {
        return WordPress::class;
    }

    public static function getProviderLabel(): string
    {
        return 'WordPress';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_WORDPRESS;
    }

    public static function getClientIdDescription(): string
    {
        return 'Client ID of WordPress OAuth2 app. For example: 130005';
    }

    public static function getClientSecretDescription(): string
    {
        return 'Client Secret of WordPress OAuth2 app. For example: PlBfJS0000000000000000000000000000000000000000000000000000EdUZJk';
    }
}
