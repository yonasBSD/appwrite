<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class DynamicKey extends Key
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Dynamic Key';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_DYNAMIC_KEY;
    }
}
