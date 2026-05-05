<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 1.9.4 Data format to 1.9.3 format
class V25 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            default => $content,
        };
    }
}
