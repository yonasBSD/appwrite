<?php

namespace Appwrite\Insights\Validator;

use Utopia\Database\Document;
use Utopia\Validator;

class InsightDocument extends Validator
{
    protected string $message = 'Value must be a non-empty insight Document with `type` and `ctas` attributes.';

    public function getDescription(): string
    {
        return $this->message;
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }

    public function isValid($value): bool
    {
        if (!$value instanceof Document) {
            return false;
        }

        if ($value->isEmpty()) {
            return false;
        }

        $type = $value->getAttribute('type');
        if (!\is_string($type) || $type === '') {
            return false;
        }

        if (!\is_array($value->getAttribute('ctas', []))) {
            return false;
        }

        return true;
    }
}
