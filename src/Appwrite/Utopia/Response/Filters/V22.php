<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response\Filter;

// Convert 1.9.1 Data format to 1.9.0 format
class V22 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            default => $content,
        };
    }
}
