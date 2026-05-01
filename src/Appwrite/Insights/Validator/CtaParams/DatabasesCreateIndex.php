<?php

namespace Appwrite\Insights\Validator\CtaParams;

use Utopia\Validator;

class DatabasesCreateIndex extends Validator
{
    /**
     * @var array<int, string>
     */
    private const REQUIRED = ['databaseId', 'collectionId', 'key', 'type', 'attributes'];

    protected string $message = 'CTA params must define databaseId, collectionId, key, type, and a non-empty attributes array.';

    public function getDescription(): string
    {
        return $this->message;
    }

    public function isArray(): bool
    {
        return true;
    }

    public function getType(): string
    {
        return self::TYPE_ARRAY;
    }

    public function isValid($value): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        foreach (self::REQUIRED as $key) {
            if (!isset($value[$key])) {
                $this->message = 'Missing required param "' . $key . '".';
                return false;
            }
        }

        if (!\is_array($value['attributes']) || $value['attributes'] === []) {
            $this->message = 'Param "attributes" must be a non-empty array of attribute keys.';
            return false;
        }

        return true;
    }
}
