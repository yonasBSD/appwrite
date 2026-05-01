<?php

namespace Tests\Unit\Insights\Validator;

use Appwrite\Insights\Validator\Ctas;
use PHPUnit\Framework\TestCase;

class CtasTest extends TestCase
{
    public function testRejectsNonArray(): void
    {
        $validator = new Ctas();

        $this->assertFalse($validator->isValid('not-an-array'));
        $this->assertFalse($validator->isValid(42));
        $this->assertFalse($validator->isValid(null));
    }

    public function testAcceptsEmptyArray(): void
    {
        $validator = new Ctas();

        $this->assertTrue($validator->isValid([]));
    }

    public function testAcceptsCompleteEntry(): void
    {
        $validator = new Ctas();

        $this->assertTrue($validator->isValid([[
            'id' => 'createIndex',
            'label' => 'Create missing index',
            'action' => 'databases.createIndex',
            'params' => [
                'databaseId' => 'main',
                'collectionId' => 'orders',
            ],
        ]]));
    }

    public function testAcceptsEntryWithoutParams(): void
    {
        $validator = new Ctas();

        $this->assertTrue($validator->isValid([[
            'id' => 'createIndex',
            'label' => 'Create missing index',
            'action' => 'databases.createIndex',
        ]]));
    }

    public function testRejectsEntryMissingRequiredKeys(): void
    {
        $validator = new Ctas();

        $this->assertFalse($validator->isValid([['id' => 'x']]));
        $this->assertFalse($validator->isValid([['id' => 'x', 'label' => 'y']]));
    }

    public function testRejectsEntryWithEmptyStrings(): void
    {
        $validator = new Ctas();

        $this->assertFalse($validator->isValid([[
            'id' => '',
            'label' => 'Create missing index',
            'action' => 'databases.createIndex',
        ]]));
    }

    public function testRejectsEntryWithNonStringFields(): void
    {
        $validator = new Ctas();

        $this->assertFalse($validator->isValid([[
            'id' => 123,
            'label' => 'Create missing index',
            'action' => 'databases.createIndex',
        ]]));
    }

    public function testRejectsEntryWithScalarParams(): void
    {
        $validator = new Ctas();

        $this->assertFalse($validator->isValid([[
            'id' => 'createIndex',
            'label' => 'Create missing index',
            'action' => 'databases.createIndex',
            'params' => 'not-a-map',
        ]]));
    }

    public function testReportsArrayType(): void
    {
        $validator = new Ctas();

        $this->assertTrue($validator->isArray());
        $this->assertSame($validator::TYPE_ARRAY, $validator->getType());
    }
}
