<?php

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\Specs;
use PHPUnit\Framework\TestCase;

class TestSpecs extends Specs
{
    public function verify(array $spec): void
    {
        $this->verifyParsedSpec($spec);
    }
}

class SpecsTest extends TestCase
{
    private TestSpecs $specs;

    protected function setUp(): void
    {
        $this->specs = new TestSpecs();
    }

    public function testVerifyParsedSpecFailsOnServiceEnumNameOverlap(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Locale (service \'locale\', enum \'Locale\')');

        $this->specs->verify([
            'tags' => [
                [
                    'name' => 'locale',
                    'description' => 'Locale APIs',
                ],
            ],
            'paths' => [
                '/account/sessions/oauth2/{provider}' => [
                    'get' => [
                        'parameters' => [
                            [
                                'name' => 'provider',
                                'schema' => [
                                    'type' => 'string',
                                    'enum' => ['en'],
                                    'x-enum-name' => 'Locale',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testVerifyParsedSpecFailsOnDerivedServiceEnumNameOverlap(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Locale (service \'locale\', enum \'Locale\')');

        $this->specs->verify([
            'tags' => [
                [
                    'name' => 'locale',
                    'description' => 'Locale APIs',
                ],
            ],
            'paths' => [
                '/projects/{projectId}/templates/email/{type}/{locale}' => [
                    'patch' => [
                        'parameters' => [
                            [
                                'name' => 'payload',
                                'in' => 'body',
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'locale' => [
                                            'type' => 'string',
                                            'enum' => ['en'],
                                            'x-enum-name' => null,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testVerifyParsedSpecAllowsDistinctServiceAndEnumNames(): void
    {
        $this->specs->verify([
            'tags' => [
                [
                    'name' => 'locale',
                    'description' => 'Locale APIs',
                ],
            ],
            'paths' => [
                '/projects/{projectId}/templates/email/{type}/{locale}' => [
                    'patch' => [
                        'parameters' => [
                            [
                                'name' => 'locale',
                                'schema' => [
                                    'type' => 'string',
                                    'enum' => ['en'],
                                    'x-enum-name' => 'EmailTemplateLocale',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->addToAssertionCount(1);
    }
}
