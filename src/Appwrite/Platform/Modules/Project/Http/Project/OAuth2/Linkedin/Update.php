<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Linkedin;

use Appwrite\Auth\OAuth2\Linkedin;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'linkedin';
    }

    public static function getProviderClass(): string
    {
        return Linkedin::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Linkedin';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Linkedin';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_LINKEDIN;
    }

    public static function getClientSecretParamName(): string
    {
        return 'primaryClientSecret';
    }

    public static function getClientIdDescription(): string
    {
        return 'Client ID of LinkedIn OAuth2 app. For example: 770000000000dv';
    }

    public static function getClientSecretDescription(): string
    {
        return 'Primary Client Secret, also known as Secondary Client Secret, of LinkedIn OAuth2 app. For example: WPL_AP1.2Bf0000000000000';
    }
}
