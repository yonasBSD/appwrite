<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V24 extends Filter
{
    // Convert 1.9.2 params to 1.9.3
    public function parse(array $content, string $model): array
    {
        return $content;
    }
}
