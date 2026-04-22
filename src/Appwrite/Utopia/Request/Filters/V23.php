<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V23 extends Filter
{
    // Convert 1.9.1 params to 1.9.2
    protected function parseEmailTemplate(array $content): array
    {
        if (isset($content['type'])) {
            $content['templateId'] = $content['type'];
            unset($content['type']);
        }

        return $content;
    }

    protected function parseReplyTo(array $content): array
    {
        if (isset($content['replyTo'])) {
            $content['replyToEmail'] = $content['replyTo'];
            unset($content['replyTo']);
        }

        return $content;
    }

    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'project.getEmailTemplate':
            case 'project.deleteEmailTemplate':
                $content = $this->parseEmailTemplate($content);
                break;
            case 'project.updateEmailTemplate':
                $content = $this->parseEmailTemplate($content);
                $content = $this->parseReplyTo($content);
                break;
            case 'project.updateSMTP':
                $content = $this->parseReplyTo($content);
                break;
        }
        return $content;
    }
}
