<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V22 extends Filter
{
    // Convert 1.9.0 params to 1.9.1
    public function parse(array $content, string $model): array
    {
        switch ($model) {
        }
        return $content;
    }
}
