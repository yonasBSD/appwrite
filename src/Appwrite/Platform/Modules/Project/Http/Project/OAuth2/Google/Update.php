<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Google;

use Appwrite\Auth\OAuth2\Google;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'google';
    }

    public static function getProviderClass(): string
    {
        return Google::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Google';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Google';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_GOOGLE;
    }

    public static function getClientIdDescription(): string
    {
        return '\'Client ID\' of Google OAuth2 app. For example: 120000000095-92ifjb00000000000000000000g7ijfb.apps.googleusercontent.com';
    }

    public static function getClientSecretDescription(): string
    {
        return '\'Client secret\' of Google OAuth2 app. For example: GOCSPX-2k8gsR0000000000000000VNahJj';
    }
}
