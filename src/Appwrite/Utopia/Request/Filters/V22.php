<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Query;
use Appwrite\Utopia\Request\Filter;

class V22 extends Filter
{
    // Convert 1.9.0 params to 1.9.1
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            // Web is special case compared to others, because it holds backwards compatibility logic
            case 'project.createWebPlatform':
                $content = $this->fillPlatformId($content);
                $content = $this->removePlatformStore($content);
                // Keep 'key' for backwards compatibility
                break;
            case 'project.updateWebPlatform':
                $content = $this->removePlatformStore($content);
                // Keep 'key' for backwards compatibility
                break;
            case 'project.createApplePlatform':
                $content = $this->fillPlatformId($content);
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'bundleIdentifier');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.updateApplePlatform':
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'bundleIdentifier');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.createAndroidPlatform':
                $content = $this->fillPlatformId($content);
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'applicationId');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.updateAndroidPlatform':
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'applicationId');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.createWindowsPlatform':
                $content = $this->fillPlatformId($content);
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'packageIdentifierName');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.updateWindowsPlatform':
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'packageIdentifierName');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.createLinuxPlatform':
                $content = $this->fillPlatformId($content);
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'packageName');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.updateLinuxPlatform':
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'packageName');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.listPlatforms':
                $content = $this->preservePlatformsQueries($content);
                break;
        }
        return $content;
    }

    protected function fillPlatformId(array $content): array
    {
        $content['platformId'] = $content['platformId'] ?? 'unique()';
        return $content;
    }

    protected function replacePlatformKey(array $content, string $newKey): array
    {
        $content[$newKey] = $content[$newKey] ?? $content['key'] ?? null;
        unset($content['key']);

        return $content;
    }

    protected function removePlatformStore(array $content): array
    {
        unset($content['store']);
        return $content;
    }

    protected function preservePlatformsQueries(array $content): array
    {
        $content['queries'] = $content['queries'] ?? [
            Query::limit(5000)
        ];

        return $content;
    }
}
