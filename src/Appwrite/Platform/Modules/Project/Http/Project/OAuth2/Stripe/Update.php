<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Stripe;

use Appwrite\Auth\OAuth2\Stripe;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'stripe';
    }

    public static function getProviderClass(): string
    {
        return Stripe::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Stripe';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Stripe';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_STRIPE;
    }

    public static function getClientSecretParamName(): string
    {
        return 'apiSecretKey';
    }

    public static function getClientIdDescription(): string
    {
        return '\'client ID\' of Stripe OAuth2 app. For example: ca_UKibXX0000000000000000000006byvR';
    }

    public static function getClientSecretDescription(): string
    {
        return '\'API Secret key\' of Stripe OAuth2 app. For example: sk_51SfOd000000000000000000000000000000000000000000000000000000000000000000000000000000000000000QGWYfp';
    }
}
