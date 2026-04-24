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
                'example' => '123456',
            ])
            ->addRule('clientSecret', [
                'type' => self::TYPE_STRING,
                'description' => 'GitHub OAuth 2 client secret.',
                'default' => '',
                'example' => 'github-client-secret',
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
