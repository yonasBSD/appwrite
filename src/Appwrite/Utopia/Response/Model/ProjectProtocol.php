<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ProjectProtocol extends Model
{
    public function __construct()
    {
        $this
        ->addRule('$id', [
            'type' => self::TYPE_STRING,
            'description' => 'Protocol ID.',
            'default' => '',
            'example' => 'email-password',
        ])
        ->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Protocol status.',
            'example' => false,
            'default' => true,
        ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'ProjectProtocol';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROJECT_PROTOCOL;
    }
}
