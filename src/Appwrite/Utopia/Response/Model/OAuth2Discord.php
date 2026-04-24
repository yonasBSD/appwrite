<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Discord extends OAuth2Base
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('clientId', [
                'type' => self::TYPE_STRING,
                'description' => 'Discord OAuth 2 client ID.',
                'default' => '',
                'example' => '950722000000343754',
            ])
            ->addRule('clientSecret', [
                'type' => self::TYPE_STRING,
                'description' => 'Discord OAuth 2 client secret.',
                'default' => '',
                'example' => 'YmPXnM000000000000000000002zFg5D',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Discord';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_DISCORD;
    }
}
