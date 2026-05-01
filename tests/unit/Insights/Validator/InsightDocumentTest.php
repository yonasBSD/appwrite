<?php

namespace Tests\Unit\Insights\Validator;

use Appwrite\Insights\Validator\InsightDocument;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

class InsightDocumentTest extends TestCase
{
    public function testAcceptsValidInsight(): void
    {
        $validator = new InsightDocument();
        $insight = new Document([
            '$id' => 'insight-1',
            'type' => 'databaseIndex',
            'ctas' => [],
        ]);

        $this->assertTrue($validator->isValid($insight));
    }

    public function testRejectsNonDocument(): void
    {
        $validator = new InsightDocument();

        $this->assertFalse($validator->isValid('not a document'));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(['type' => 'databaseIndex']));
    }

    public function testRejectsEmptyDocument(): void
    {
        $validator = new InsightDocument();

        $this->assertFalse($validator->isValid(new Document()));
    }

    public function testRejectsMissingType(): void
    {
        $validator = new InsightDocument();
        $insight = new Document([
            '$id' => 'insight-1',
            'ctas' => [],
        ]);

        $this->assertFalse($validator->isValid($insight));
    }

    public function testRejectsNonArrayCtas(): void
    {
        $validator = new InsightDocument();
        $insight = new Document([
            '$id' => 'insight-1',
            'type' => 'databaseIndex',
            'ctas' => 'not-an-array',
        ]);

        $this->assertFalse($validator->isValid($insight));
    }

    public function testReportsObjectType(): void
    {
        $validator = new InsightDocument();

        $this->assertSame('object', $validator->getType());
        $this->assertFalse($validator->isArray());
    }
}
