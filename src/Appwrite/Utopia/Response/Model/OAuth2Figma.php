<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Figma extends OAuth2Base
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('clientId', [
                'type' => self::TYPE_STRING,
                'description' => 'Figma OAuth 2 client ID.',
                'default' => '',
                'example' => 'byay5H0000000000VtiI40',
            ])
            ->addRule('clientSecret', [
                'type' => self::TYPE_STRING,
                'description' => 'Figma OAuth 2 client secret.',
                'default' => '',
                'example' => 'yEpOYn0000000000000000004iIsU5',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Figma';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_FIGMA;
    }
}
