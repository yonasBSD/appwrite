<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Salesforce;

use Appwrite\Auth\OAuth2\Salesforce;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'salesforce';
    }

    public static function getProviderClass(): string
    {
        return Salesforce::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Salesforce';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_SALESFORCE;
    }

    public static function getClientIdParamName(): string
    {
        return 'customerKey';
    }

    public static function getClientSecretParamName(): string
    {
        return 'customerSecret';
    }

    public static function getClientIdDescription(): string
    {
        return 'Consumer key of Salesforce OAuth2 app. For example: 3MVG9I0000000000000000000000000000000000000000000000000000000000000000000000000C5Aejq';
    }

    public static function getClientSecretDescription(): string
    {
        return 'Consumer secret of Salesforce OAuth2 app. For example: 3w000000000000e2';
    }
}
