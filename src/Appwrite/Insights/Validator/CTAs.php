<?php

namespace Appwrite\Insights\Validator;

use Utopia\Validator;

class CTAs extends Validator
{
    protected string $message = 'Value must be an array of CTA descriptors. Each entry must define `id`, `label`, `action`, and an optional `params` object.';

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

        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                return false;
            }

            foreach (['id', 'label', 'action'] as $required) {
                if (!isset($entry[$required]) || !\is_string($entry[$required]) || $entry[$required] === '') {
                    return false;
                }
            }

            if (isset($entry['params']) && !\is_array($entry['params']) && !\is_object($entry['params'])) {
                return false;
            }
        }

        return true;
    }
}
