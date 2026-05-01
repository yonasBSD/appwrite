<?php

namespace Tests\Unit\Insights;

use Appwrite\Extend\Exception;
use Appwrite\Insights\Cta\Action;
use Appwrite\Insights\Cta\Action\DatabasesCreateIndex;
use Appwrite\Insights\Cta\Registry;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;

class CtaRegistryTest extends TestCase
{
    public function testRegisterAndResolve(): void
    {
        $registry = new Registry();
        $action = new DatabasesCreateIndex();

        $this->assertFalse($registry->has($action->getName()));

        $registry->register($action);

        $this->assertTrue($registry->has($action->getName()));
        $this->assertSame($action, $registry->get($action->getName()));
    }

    public function testGetUnknownActionThrows(): void
    {
        $registry = new Registry();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('CTA action "missing.action" is not registered.');

        $registry->get('missing.action');
    }

    public function testHasReturnsFalseForUnknownAction(): void
    {
        $registry = new Registry();

        $this->assertFalse($registry->has('missing.action'));
    }

    public function testAllReturnsRegisteredActions(): void
    {
        $registry = new Registry();
        $action = new DatabasesCreateIndex();
        $registry->register($action);

        $all = $registry->all();

        $this->assertCount(1, $all);
        $this->assertArrayHasKey($action->getName(), $all);
        $this->assertSame($action, $all[$action->getName()]);
    }

    public function testRegisterReplacesExistingAction(): void
    {
        $registry = new Registry();
        $first = new DatabasesCreateIndex();
        $second = new class () implements Action {
            public function getName(): string
            {
                return INSIGHT_CTA_ACTION_DATABASES_CREATE_INDEX;
            }

            public function getRequiredScope(): string
            {
                return 'databases.write';
            }

            public function validate(array $params): void
            {
            }

            public function execute(array $params, Document $insight, Document $project, Database $dbForProject): Document
            {
                return new Document(['ok' => true]);
            }
        };

        $registry->register($first);
        $registry->register($second);

        $this->assertSame($second, $registry->get($first->getName()));
    }
}
