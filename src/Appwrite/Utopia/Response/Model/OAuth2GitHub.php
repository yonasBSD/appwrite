<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2GitHub extends OAuth2Base
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('clientId', [
                'type' => self::TYPE_STRING,
                'description' => 'GitHub OAuth 2 client ID. For GitHub Apps, use the "App ID" when both an App ID and client ID are available.',
                'default' => '',
                'example' => 'e4d87900000000540733',
            ])
            ->addRule('clientSecret', [
                'type' => self::TYPE_STRING,
                'description' => 'GitHub OAuth 2 client secret.',
                'default' => '',
                'example' => '5e07c00000000000000000000000000000198bcc',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2GitHub';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_GITHUB;
    }
}
