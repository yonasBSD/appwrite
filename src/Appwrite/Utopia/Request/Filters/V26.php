<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V26 extends Filter
{
    // Convert 1.9.4 params to 1.9.5
    public function parse(array $content, string $model): array
    {
        return $content;
    }
}
