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
            // Web is special case, it has backwards compatibility
            Response::MODEL_PLATFORM_WEB => $this->parsePlatform($content),
            Response::MODEL_PLATFORM_APPLE => $this->parsePlatform($content),
            Response::MODEL_PLATFORM_ANDROID => $this->parsePlatform($content),
            Response::MODEL_PLATFORM_WINDOWS => $this->parsePlatform($content),
            Response::MODEL_PLATFORM_LINUX => $this->parsePlatform($content),
            Response::MODEL_PLATFORM_LIST => $this->handleList(
                $content,
                "platforms",
                fn ($item) => $this->parsePlatform($item),
            ),
            Response::MODEL_PROJECT => $this->parseProjectForPlatform($content),
            Response::MODEL_PROJECT_LIST => $this->handleList(
                $content,
                "projects",
                fn ($item) => $this->parseProjectForPlatform($item),
            ),
            default => $content,
        };
    }

    protected function parseProjectForPlatform(array $content): array
    {
        // Parse platforms under project, since it's a subquery
        $content['platforms'] = \array_map(fn ($item) => $this->parsePlatform($item), $content['platforms']);
        return $content;
    }

    protected function parsePlatform(array $content): array
    {
        // Map platform-specific identifier fields back to 'key'
        $content['key'] =
            ($content['bundleIdentifier'] ?? '')
            ?: ($content['applicationId'] ?? '')
            ?: ($content['packageIdentifierName'] ?? '')
            ?: ($content['packageName'] ?? '')
            ?: ($content['key'] ?? '')
            ?: '';

        unset($content['bundleIdentifier']);
        unset($content['applicationId']);
        unset($content['packageIdentifierName']);
        unset($content['packageName']);

        // Restore fields removed in v1.9
        $content['store'] = $content['store'] ?? '';
        $content['hostname'] = $content['hostname'] ?? '';

        return $content;
    }
}
