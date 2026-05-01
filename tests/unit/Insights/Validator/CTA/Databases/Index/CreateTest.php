<?php

namespace Tests\Unit\Insights\Validator\CTA\Databases\Index;

use Appwrite\Insights\Validator\CTA\Databases\Index\Create;
use PHPUnit\Framework\TestCase;

class CreateTest extends TestCase
{
    public function testAcceptsCompleteParams(): void
    {
        $validator = new Create();

        $this->assertTrue($validator->isValid([
            'databaseId' => 'main',
            'collectionId' => 'orders',
            'key' => '_idx_status',
            'type' => 'key',
            'attributes' => ['status'],
        ]));
    }

    public function testRejectsNonArray(): void
    {
        $validator = new Create();

        $this->assertFalse($validator->isValid('not-an-array'));
        $this->assertFalse($validator->isValid(null));
    }

    public function testRejectsMissingRequiredParam(): void
    {
        $validator = new Create();

        $this->assertFalse($validator->isValid([
            'databaseId' => 'main',
            'collectionId' => 'orders',
            'key' => '_idx_status',
            'type' => 'key',
        ]));
        $this->assertStringContainsString('attributes', $validator->getDescription());
    }

    public function testRejectsEmptyAttributes(): void
    {
        $validator = new Create();

        $this->assertFalse($validator->isValid([
            'databaseId' => 'main',
            'collectionId' => 'orders',
            'key' => '_idx_status',
            'type' => 'key',
            'attributes' => [],
        ]));
        $this->assertStringContainsString('non-empty', $validator->getDescription());
    }

    public function testReportsArrayType(): void
    {
        $validator = new Create();

        $this->assertTrue($validator->isArray());
        $this->assertSame($validator::TYPE_ARRAY, $validator->getType());
    }
}
