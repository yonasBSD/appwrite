<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 1.9.3 Data format to 1.9.2 format
class V24 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            Response::MODEL_DYNAMIC_KEY => $this->parseDynamicKey($content),
            default => $content,
        };
    }

    private function parseDynamicKey(array $content): array
    {
        unset($content['$id']);
        unset($content['$createdAt']);
        unset($content['$updatedAt']);
        unset($content['name']);
        unset($content['expire']);
        unset($content['sdks']);
        unset($content['accessedAt']);

        $content['jwt'] = $content['secret'] ?? '';
        unset($content['secret']);

        $content['projectId'] = 'WHAT DO I DO NOW?!';

        return $content;
    }
}
