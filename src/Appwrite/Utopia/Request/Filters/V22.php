<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V22 extends Filter
{
    // Convert 1.9.0 params to 1.9.1
    protected function parseUpdateProtocolStatus(array $content): array
    {
        if (isset($content['api'])) {
            $content['protocolId'] = $content['api'];
            unset($content['api']);
        }

        if (isset($content['status'])) {
            $content['enabled'] = $content['status'];
            unset($content['status']);
        }

        if (($content['protocolId'] ?? '') === 'realtime') {
            $content['protocolId'] = 'websocket';
        }

        return $content;
    }

    protected function parseUpdateServiceStatus(array $content): array
    {
        if (isset($content['service'])) {
            $content['serviceId'] = $content['service'];
            unset($content['service']);
        }

        if (isset($content['status'])) {
            $content['enabled'] = $content['status'];
            unset($content['status']);
        }

        return $content;
    }

    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'project.updateServiceStatus':
                $content = $this->parseUpdateServiceStatus($content);
                break;
            case 'project.updateProtocolStatus':
                $content = $this->parseUpdateProtocolStatus($content);
                break;
        }
        return $content;
    }
}
