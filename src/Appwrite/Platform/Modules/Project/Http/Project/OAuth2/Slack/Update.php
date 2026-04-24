<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Slack;

use Appwrite\Auth\OAuth2\Slack;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'slack';
    }

    public static function getProviderClass(): string
    {
        return Slack::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Slack';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Slack';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_SLACK;
    }

    public static function getClientIdDescription(): string
    {
        return 'Client ID of Slack OAuth2 app. For example: 23000000089.15000000000023';
    }

    public static function getClientSecretDescription(): string
    {
        return 'Client Secret of Slack OAuth2 app. For example: 81656000000000000000000000f3d2fd';
    }
}
