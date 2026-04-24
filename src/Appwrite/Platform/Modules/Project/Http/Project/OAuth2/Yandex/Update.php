<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Yandex;

use Appwrite\Auth\OAuth2\Yandex;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'yandex';
    }

    public static function getProviderClass(): string
    {
        return Yandex::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Yandex';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_YANDEX;
    }

    public static function getClientIdDescription(): string
    {
        return 'ClientID of Yandex OAuth2 app. For example: 6a8a6a0000000000000000000091483c';
    }

    public static function getClientSecretDescription(): string
    {
        return 'Client secret of Yandex OAuth2 app. For example: bbf98500000000000000000000c75a63';
    }
}
