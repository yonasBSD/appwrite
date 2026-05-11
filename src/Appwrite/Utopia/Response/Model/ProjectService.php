<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ProjectService extends Model
{
    public function __construct()
    {
        $this
        ->addRule('$id', [
            'type' => self::TYPE_STRING,
            'description' => 'Service ID.',
            'default' => '',
            'example' => 'email-password',
        ])
        ->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Service status.',
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
        return 'ProjectService';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROJECT_SERVICE;
    }
}
