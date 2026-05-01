<?php

namespace Tests\Unit\Insights;

use Appwrite\Extend\Exception;
use Appwrite\Insights\Cta\Action\DatabasesCreateIndex;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;

#[AllowMockObjectsWithoutExpectations]
class ActionTest extends TestCase
{
    public function testNameAndScope(): void
    {
        $action = new DatabasesCreateIndex();

        $this->assertSame(INSIGHT_CTA_ACTION_DATABASES_CREATE_INDEX, $action->getName());
        $this->assertSame('databases.write', $action->getRequiredScope());
    }

    public function testValidateAcceptsCompleteParams(): void
    {
        $action = new DatabasesCreateIndex();

        $action->validate([
            'databaseId' => 'main',
            'collectionId' => 'orders',
            'key' => '_idx_status',
            'type' => 'key',
            'attributes' => ['status'],
        ]);

        $this->expectNotToPerformAssertions();
    }

    #[DataProvider('missingParamProvider')]
    public function testValidateFailsForMissingParams(string $missing, array $params): void
    {
        $action = new DatabasesCreateIndex();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing required param "' . $missing . '"');

        $action->validate($params);
    }

    public static function missingParamProvider(): array
    {
        $base = [
            'databaseId' => 'main',
            'collectionId' => 'orders',
            'key' => '_idx_status',
            'type' => 'key',
            'attributes' => ['status'],
        ];

        $cases = [];
        foreach (\array_keys($base) as $key) {
            $partial = $base;
            unset($partial[$key]);
            $cases[$key] = [$key, $partial];
        }

        return $cases;
    }

    public function testValidateRejectsEmptyAttributes(): void
    {
        $action = new DatabasesCreateIndex();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Param "attributes" must be a non-empty array');

        $action->validate([
            'databaseId' => 'main',
            'collectionId' => 'orders',
            'key' => '_idx_status',
            'type' => 'key',
            'attributes' => [],
        ]);
    }

    public function testExecuteThrowsNotImplemented(): void
    {
        $action = new DatabasesCreateIndex();

        $insight = new Document(['$id' => 'insight1']);
        $project = new Document(['$id' => 'project1']);
        $database = $this->createMock(Database::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('is not implemented in this build');

        $action->execute([
            'databaseId' => 'main',
            'collectionId' => 'orders',
            'key' => '_idx_status',
            'type' => 'key',
            'attributes' => ['status'],
        ], $insight, $project, $database);
    }
}
