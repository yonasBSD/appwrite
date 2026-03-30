<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class AttributeBigInt extends AttributeInteger
{
    public array $conditions = [
        'type' => 'bigint',
        'size' => 8,
    ];

    public function __construct()
    {
        parent::__construct();

        // Update example for the `type` field
        $this->rules['type']['example'] = 'bigint';
    }

    public function getName(): string
    {
        return 'AttributeBigInt';
    }

    public function getType(): string
    {
        return Response::MODEL_ATTRIBUTE_BIGINT;
    }
}
