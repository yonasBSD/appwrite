<?php

namespace Tests\Unit\SDK\Specification;

use Appwrite\SDK\Specification\Format;
use PHPUnit\Framework\TestCase;
use Utopia\DI\Container;

class FormatTest extends TestCase
{
    private Format $format;

    protected function setUp(): void
    {
        parent::setUp();

        $this->format = new class (new Container(), [], [], [], [], 0, 'console') extends Format {
            public function getName(): string
            {
                return 'test';
            }

            public function parse(): array
            {
                return [];
            }

            public function requestParameterRequired(string $service, string $method, string $param, bool $required): bool
            {
                return $this->isRequestParameterRequired($service, $method, $param, $required);
            }

            public function requestParameterNullable(string $service, string $method, string $param, bool $nullable): bool
            {
                return $this->isRequestParameterNullable($service, $method, $param, $nullable);
            }

            public function requestParameterDefault(string $service, string $method, string $param, bool $optional, mixed $default): bool
            {
                return $this->shouldEmitRequestParameterDefault($service, $method, $param, $optional, $default);
            }
        };
    }

    public function testProjectRequestParameterOverrides(): void
    {
        $this->assertTrue($this->format->requestParameterRequired('project', 'createWebPlatform', 'hostname', false));
        $this->assertTrue($this->format->requestParameterRequired('project', 'updateWebPlatform', 'hostname', false));
        $this->assertFalse($this->format->requestParameterNullable('project', 'createKey', 'scopes', true));
        $this->assertFalse($this->format->requestParameterNullable('project', 'updateKey', 'scopes', true));
        $this->assertFalse($this->format->requestParameterDefault('project', 'createWebPlatform', 'hostname', true, ''));
        $this->assertFalse($this->format->requestParameterDefault('project', 'updateWebPlatform', 'hostname', true, ''));
        $this->assertTrue($this->format->requestParameterDefault('project', 'listPlatforms', 'queries', true, []));
    }

    public function testProjectPlatformResponseTypeUsesSharedEnumName(): void
    {
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformAndroid', 'type'));
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformWeb', 'type'));
    }

    public function testExistingResponseEnumMappingsRemainUnchanged(): void
    {
        $this->assertSame('HealthCheckStatus', $this->format->getResponseEnumName('healthStatus', 'status'));
        $this->assertNull($this->format->getResponseEnumName('key', 'name'));
    }
}
