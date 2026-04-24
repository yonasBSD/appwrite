<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Yahoo;

use Appwrite\Auth\OAuth2\Yahoo;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'yahoo';
    }

    public static function getProviderClass(): string
    {
        return Yahoo::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Yahoo';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_YAHOO;
    }

    public static function getClientIdDescription(): string
    {
        return 'Client ID, also known as Customer Key, of Yahoo OAuth2 app. For example: dj0yJm000000000000000000000000000000000000000000000000000000000000000000000000000000000000Z4PWRm';
    }

    public static function getClientSecretDescription(): string
    {
        return 'Client Secret, also known as Customer Secret, of Yahoo OAuth2 app. For example: cf978f0000000000000000000000000000c5e2e9';
    }
}
