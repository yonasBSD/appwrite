<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response\Filter;

// Convert 1.9.5 Data format to 1.9.4 format
class V26 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return $content;
    }
}
