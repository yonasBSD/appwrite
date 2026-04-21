<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V23 extends Filter
{
    // Convert 1.9.1 params to 1.9.2
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'project.updateMembershipPrivacyPolicy':
                $content = $this->parseUpdateMembershipPrivacyPolicy($content);
                break;
        }

        return $content;
    }

    protected function parseUpdateMembershipPrivacyPolicy(array $content): array
    {
        $content['userId'] = false;
        $content['userPhone'] = false;

        return $content;
    }
}
