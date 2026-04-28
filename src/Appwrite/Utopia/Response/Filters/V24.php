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

        $projectId = '';
        if (isset($content['secret'])) {
            $parts = explode('_', $content['secret'], 2);
            if (count($parts) === 2) {
                $jwtParts = explode('.', $parts[1]);
                if (count($jwtParts) >= 2) {
                    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $jwtParts[1])), true);
                    $projectId = $payload['projectId'] ?? '';
                }
            }
        }
        $content['projectId'] = $projectId;

        $content['jwt'] = $content['secret'] ?? '';
        unset($content['secret']);

        return $content;
    }
}
