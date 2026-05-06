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
            Response::MODEL_OAUTH2_OIDC => $this->parseOAuth2Oidc($content),
            default => $content,
        };
    }

    private function parseOAuth2Oidc(array $content): array
    {
        if (isset($content['tokenURL'])) {
            $content['tokenUrl'] = $content['tokenURL'];
            unset($content['tokenURL']);
        }

        if (isset($content['userInfoURL'])) {
            $content['userInfoUrl'] = $content['userInfoURL'];
            unset($content['userInfoURL']);
        }

        return $content;
    }
}
