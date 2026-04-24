<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Box;

use Appwrite\Auth\OAuth2\Box;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'box';
    }

    public static function getProviderClass(): string
    {
        return Box::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Box';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_BOX;
    }

    public static function getClientIdDescription(): string
    {
        return 'Client ID of Box OAuth2 app. For example: deglcs00000000000000000000x2og6y';
    }

    public static function getClientSecretDescription(): string
    {
        return 'Client Secret of Box OAuth2 app. For example: OKM1f100000000000000000000eshEif';
    }
}
