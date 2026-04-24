<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model;

abstract class OAuth2Base extends Model
{
    public function __construct()
    {
        $this
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'OAuth 2 provider is active and can be used to create sessions.',
                'default' => false,
                'example' => false,
            ]);
    }
}
