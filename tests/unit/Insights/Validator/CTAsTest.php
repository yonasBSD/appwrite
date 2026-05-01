<?php

namespace Tests\Unit\Insights\Validator;

use Appwrite\Insights\Validator\CTAs;
use PHPUnit\Framework\TestCase;

class CTAsTest extends TestCase
{
    public function testRejectsNonArray(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid('not-an-array'));
        $this->assertFalse($validator->isValid(42));
        $this->assertFalse($validator->isValid(null));
    }

    public function testAcceptsEmptyArray(): void
    {
        $validator = new CTAs();

        $this->assertTrue($validator->isValid([]));
    }

    public function testAcceptsCompleteEntry(): void
    {
        $validator = new CTAs();

        $this->assertTrue($validator->isValid([[
            'id' => 'createIndex',
            'label' => 'Create missing index',
            'action' => 'databases.indexes.create',
            'params' => [
                'databaseId' => 'main',
                'collectionId' => 'orders',
            ],
        ]]));
    }

    public function testAcceptsEntryWithoutParams(): void
    {
        $validator = new CTAs();

        $this->assertTrue($validator->isValid([[
            'id' => 'createIndex',
            'label' => 'Create missing index',
            'action' => 'databases.indexes.create',
        ]]));
    }

    public function testRejectsEntryMissingRequiredKeys(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([['id' => 'x']]));
        $this->assertFalse($validator->isValid([['id' => 'x', 'label' => 'y']]));
    }

    public function testRejectsEntryWithEmptyStrings(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'id' => '',
            'label' => 'Create missing index',
            'action' => 'databases.indexes.create',
        ]]));
    }

    public function testRejectsEntryWithNonStringFields(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'id' => 123,
            'label' => 'Create missing index',
            'action' => 'databases.indexes.create',
        ]]));
    }

    public function testRejectsEntryWithScalarParams(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'id' => 'createIndex',
            'label' => 'Create missing index',
            'action' => 'databases.indexes.create',
            'params' => 'not-a-map',
        ]]));
    }

    public function testReportsArrayType(): void
    {
        $validator = new CTAs();

        $this->assertTrue($validator->isArray());
        $this->assertSame($validator::TYPE_ARRAY, $validator->getType());
    }
}
