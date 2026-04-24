<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Dropbox extends OAuth2Base
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('appKey', [
                'type' => self::TYPE_STRING,
                'description' => 'Dropbox OAuth 2 app key.',
                'default' => '',
                'example' => 'jl000000000009t',
            ])
            ->addRule('appSecret', [
                'type' => self::TYPE_STRING,
                'description' => 'Dropbox OAuth 2 app secret.',
                'default' => '',
                'example' => 'g200000000000vw',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Dropbox';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_DROPBOX;
    }
}
