<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 1.9.1 Data format to 1.9.0 format
class V22 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            Response::MODEL_PROJECT => $this->parseProject($content),
            default => $content,
        };
    }

    private function parseProject(array $content): array
    {
        foreach (['protocolStatusForRest', 'protocolStatusForGraphql', 'protocolStatusForWebsocket'] as $field) {
            unset($content[$field]);
        }
        return $content;
    }
}
