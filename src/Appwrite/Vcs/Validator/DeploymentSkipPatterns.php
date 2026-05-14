<?php

namespace Appwrite\Vcs\Validator;

use Utopia\Validator\Contains;

class DeploymentSkipPatterns extends Contains
{
    private const PATTERNS = [
        '[skip ci]',
    ];

    public function __construct()
    {
        parent::__construct(self::PATTERNS);
    }
}
