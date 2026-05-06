<?php

namespace Appwrite\Insights\Validator;

use Utopia\Validator;

class CTAs extends Validator
{
    public const MAX_COUNT_DEFAULT = 16;

    protected string $message = 'Value must be an array of CTA descriptors. Each entry must define `id`, `label`, `service`, `method`, and an optional `params` object.';

    public function __construct(protected int $maxCount = self::MAX_COUNT_DEFAULT)
    {
    }

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

        if (\count($value) > $this->maxCount) {
            $this->message = "A maximum of {$this->maxCount} CTAs are allowed per insight.";
            return false;
        }

        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                return false;
            }

            foreach (['id', 'label', 'service', 'method'] as $required) {
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
