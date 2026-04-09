<?php

namespace Tests\Unit\SDK\Specification;

use Appwrite\SDK\Specification\Format;
use PHPUnit\Framework\TestCase;
use Utopia\DI\Container;
use Utopia\Validator\Text;

class FormatTest extends TestCase
{
    public function testResolvesCallableValidatorResourcesFromContainer(): void
    {
        $container = new Container();
        $container->set('first', fn () => 'alpha');
        $container->set('second', fn () => 'beta');

        $format = new class($container, [], [], [], [], 0, APP_SDK_PLATFORM_SERVER) extends Format {
            public function getName(): string
            {
                return 'stub';
            }

            public function parse(): array
            {
                return [];
            }

            public function resolveValidatorForTest(array $param): mixed
            {
                return $this->getValidator($param);
            }
        };

        $validator = $format->resolveValidatorForTest([
            'validator' => fn (string $first, string $second) => new Text(
                ($first === 'alpha' && $second === 'beta') ? 9 : 1
            ),
            'injections' => ['second', 'first'],
        ]);

        $this->assertInstanceOf(Text::class, $validator);
        $this->assertTrue($validator->isValid('123456789'));
        $this->assertFalse($validator->isValid('1234567890'));
    }
}
