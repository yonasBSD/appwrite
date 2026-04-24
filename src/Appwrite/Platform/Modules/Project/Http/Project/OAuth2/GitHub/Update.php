<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\GitHub;

use Appwrite\Auth\OAuth2\Github;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'github';
    }

    public static function getProviderClass(): string
    {
        return Github::class;
    }

    public static function getProviderLabel(): string
    {
        return 'GitHub';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_GITHUB;
    }

    public static function getClientIdDescription(): string
    {
        return 'Client ID of GitHub OAuth2 app, or App ID of GitHub generic app. For example: e4d87900000000540733';
    }

    public static function getClientSecretDescription(): string
    {
        return 'Client secret of GitHub OAuth2 app, or GitHub generic app. For example: 5e07c00000000000000000000000000000198bcc';
    }
}
