<?php

namespace Tests\Unit\SDK\Specification;

use Appwrite\SDK\Specification\Format;
use PHPUnit\Framework\TestCase;
use Utopia\DI\Container;

class TestFormat extends Format
{
    public function getName(): string
    {
        return 'test';
    }

    public function parse(): array
    {
        return [];
    }

    public function requestParameterConfig(string $service, string $method, string $param, bool $optional, bool $nullable, mixed $default): array
    {
        return $this->getRequestParameterConfig($service, $method, $param, $optional, $nullable, $default);
    }
}

class FormatTest extends TestCase
{
    private TestFormat $format;

    protected function setUp(): void
    {
        parent::setUp();

        $this->format = new TestFormat(new Container(), [], [], [], [], 0, 'console');
    }

    public function testProjectRequestParameterOverrides(): void
    {
        $createWebPlatform = $this->format->requestParameterConfig('project', 'createWebPlatform', 'hostname', true, false, '');
        $updateWebPlatform = $this->format->requestParameterConfig('project', 'updateWebPlatform', 'hostname', true, false, '');
        $listPlatforms = $this->format->requestParameterConfig('project', 'listPlatforms', 'queries', true, false, []);

        $this->assertTrue($createWebPlatform['required']);
        $this->assertFalse($createWebPlatform['emitDefault']);
        $this->assertTrue($updateWebPlatform['required']);
        $this->assertFalse($updateWebPlatform['emitDefault']);
        $this->assertTrue($listPlatforms['emitDefault']);
    }

    public function testProjectPlatformResponseTypeUsesSharedEnumName(): void
    {
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformAndroid', 'type'));
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformWeb', 'type'));
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformApple', 'type'));
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformWindows', 'type'));
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformLinux', 'type'));
        $this->assertNull($this->format->getResponseEnumName('platformList', 'type'));
    }

    public function testExistingResponseEnumMappingsRemainUnchanged(): void
    {
        $this->assertSame('HealthCheckStatus', $this->format->getResponseEnumName('healthStatus', 'status'));
        $this->assertNull($this->format->getResponseEnumName('key', 'name'));
    }

    public function testPlatformListIdUsesSharedPlatformTypeEnum(): void
    {
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformList', '$id'));
        $this->assertNull($this->format->getResponseEnumName('platformList', 'name'));
        $this->assertNull($this->format->getResponseEnumName('other', '$id'));
    }

    public function testEnumSDKNameOverrideTakesPrecedence(): void
    {
        $this->assertSame('AuthMethodId', $this->format->getResponseEnumName('projectAuthMethod', 'method', 'AuthMethodId'));

        // Override wins even on models that have their own mapping.
        $this->assertSame('CustomName', $this->format->getResponseEnumName('platformAndroid', 'type', 'CustomName'));
        $this->assertSame('CustomName', $this->format->getResponseEnumName('healthStatus', 'status', 'CustomName'));

        // Override produces an enum name for params that otherwise wouldn't get one.
        $this->assertSame('AuthMethodId', $this->format->getResponseEnumName('key', 'name', 'AuthMethodId'));
    }

    public function testEnumSDKNameEmptyOrNullFallsThroughToDefaults(): void
    {
        $this->assertNull($this->format->getResponseEnumName('key', 'name', null));
        $this->assertNull($this->format->getResponseEnumName('key', 'name', ''));

        // Falsy override should not block the existing platform mapping.
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformWeb', 'type', null));
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformWeb', 'type', ''));
    }
}
