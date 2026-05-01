<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Insights extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'type',
        'severity',
        'resourceType',
        'resourceId',
        'analyzedAt',
        'dismissedAt',
        'dismissedBy',
    ];

    public function __construct()
    {
        parent::__construct('insights', self::ALLOWED_ATTRIBUTES);
    }
}
