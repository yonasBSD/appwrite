<?php

namespace Appwrite\Vcs\Validator;

use Utopia\Validator;

class DeploymentSkipPatterns extends Validator
{
    private const PATTERNS = [
        '[skip ci]',
        '[ci skip]',
        '[no ci]',
        '[skip action]',
        '[action skip]',
        '[no action]',
        '[skip actions]',
        '[actions skip]',
        '[no actions]',
        '[skip deploy]',
        '[deploy skip]',
        '[no deploy]',
        '[skip appwrite]',
        '[appwrite skip]',
        '[no appwrite]',
    ];

    /**
     * Returns false (skip deployment) when the commit message contains any of the
     * known skip directives as a standalone directive (case-insensitive).
     * Returns true (proceed) when none match.
     *
     * Matching rules:
     * - Case-insensitive
     */
    public function isValid($value): bool
    {
        if (!is_string($value)) {
            return true;
        }

        $value = strtolower($value);

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($value, $pattern)) {
                return false;
            }
        }

        return true;
    }

    public function getDescription(): string
    {
        return 'Commit message must not contain any of the configured skip patterns.';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
