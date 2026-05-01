<?php

namespace Appwrite\Insights\Validator;

use Utopia\Database\Document;
use Utopia\Validator;

class Project extends Validator
{
    protected string $message = 'Value must be a non-empty project Document with an `$id`.';

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

        return $value->getId() !== '';
    }
}
