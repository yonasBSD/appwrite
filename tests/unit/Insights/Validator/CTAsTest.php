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
            'service' => 'tablesDB',
            'method' => 'createIndex',
            'params' => [
                'databaseId' => 'main',
                'tableId' => 'orders',
            ],
        ]]));
    }

    public function testAcceptsEntryWithoutParams(): void
    {
        $validator = new CTAs();

        $this->assertTrue($validator->isValid([[
            'id' => 'createIndex',
            'label' => 'Create missing index',
            'service' => 'tablesDB',
            'method' => 'createIndex',
        ]]));
    }

    public function testRejectsEntryMissingRequiredKeys(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([['id' => 'x']]));
        $this->assertFalse($validator->isValid([['id' => 'x', 'label' => 'y']]));
        $this->assertFalse($validator->isValid([['id' => 'x', 'label' => 'y', 'service' => 'tablesDB']]));
        $this->assertFalse($validator->isValid([['id' => 'x', 'label' => 'y', 'method' => 'createIndex']]));
    }

    public function testRejectsEntryWithEmptyStrings(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'id' => '',
            'label' => 'Create missing index',
            'service' => 'tablesDB',
            'method' => 'createIndex',
        ]]));
    }

    public function testRejectsEntryWithNonStringFields(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'id' => 123,
            'label' => 'Create missing index',
            'service' => 'tablesDB',
            'method' => 'createIndex',
        ]]));
    }

    public function testRejectsEntryWithScalarParams(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'id' => 'createIndex',
            'label' => 'Create missing index',
            'service' => 'tablesDB',
            'method' => 'createIndex',
            'params' => 'not-a-map',
        ]]));
    }

    public function testReportsArrayType(): void
    {
        $validator = new CTAs();

        $this->assertTrue($validator->isArray());
        $this->assertSame($validator::TYPE_ARRAY, $validator->getType());
    }

    public function testRejectsMoreThanMaxCount(): void
    {
        $validator = new CTAs(maxCount: 3);

        $entries = [];
        for ($i = 0; $i < 4; $i++) {
            $entries[] = [
                'id' => 'cta-' . $i,
                'label' => 'Label ' . $i,
                'service' => 'tablesDB',
                'method' => 'createIndex',
            ];
        }

        $this->assertFalse($validator->isValid($entries));
        $this->assertStringContainsString('maximum of 3', $validator->getDescription());
    }

    public function testAcceptsExactlyMaxCount(): void
    {
        $validator = new CTAs(maxCount: 3);

        $entries = [];
        for ($i = 0; $i < 3; $i++) {
            $entries[] = [
                'id' => 'cta-' . $i,
                'label' => 'Label ' . $i,
                'service' => 'tablesDB',
                'method' => 'createIndex',
            ];
        }

        $this->assertTrue($validator->isValid($entries));
    }

    public function testAcceptsObjectParams(): void
    {
        $validator = new CTAs();

        $entry = [
            'id' => 'createIndex',
            'label' => 'Create missing index',
            'service' => 'tablesDB',
            'method' => 'createIndex',
            'params' => new \stdClass(),
        ];

        $this->assertTrue($validator->isValid([$entry]));
    }

    public function testRejectsEntryWithEmptyService(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'id' => 'createIndex',
            'label' => 'Create missing index',
            'service' => '',
            'method' => 'createIndex',
        ]]));
    }

    public function testRejectsEntryWithEmptyMethod(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'id' => 'createIndex',
            'label' => 'Create missing index',
            'service' => 'tablesDB',
            'method' => '',
        ]]));
    }

    public function testRejectsEntryWithEmptyLabel(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'id' => 'createIndex',
            'label' => '',
            'service' => 'tablesDB',
            'method' => 'createIndex',
        ]]));
    }

    public function testDefaultMaxCountIsSixteen(): void
    {
        $validator = new CTAs();

        $this->assertSame(CTAs::MAX_COUNT_DEFAULT, 16);

        $entries = [];
        for ($i = 0; $i < 16; $i++) {
            $entries[] = [
                'id' => 'cta-' . $i,
                'label' => 'Label ' . $i,
                'service' => 'tablesDB',
                'method' => 'createIndex',
            ];
        }

        $this->assertTrue($validator->isValid($entries));

        $entries[] = [
            'id' => 'cta-16',
            'label' => 'Label 16',
            'service' => 'tablesDB',
            'method' => 'createIndex',
        ];

        $this->assertFalse($validator->isValid($entries));
    }
}
