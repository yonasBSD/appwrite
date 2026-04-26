<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Discord;

use Appwrite\Auth\OAuth2\Discord;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'discord';
    }

    public static function getProviderClass(): string
    {
        return Discord::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Discord';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Discord';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_DISCORD;
    }

    public static function getClientIdDescription(): string
    {
        return '\'Client ID\' of Discord OAuth2 app. For example: 950722000000343754';
    }

    public static function getClientSecretDescription(): string
    {
        return '\'Client Secret\' of Discord OAuth2 app. For example: YmPXnM000000000000000000002zFg5D';
    }
}
