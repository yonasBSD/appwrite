<?php

namespace Tests\Unit\Insights\Validator;

use Appwrite\Insights\Validator\ProjectDocument;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

class ProjectDocumentTest extends TestCase
{
    public function testAcceptsValidProject(): void
    {
        $validator = new ProjectDocument();
        $project = new Document([
            '$id' => 'project-1',
            'name' => 'Test',
        ]);

        $this->assertTrue($validator->isValid($project));
    }

    public function testRejectsNonDocument(): void
    {
        $validator = new ProjectDocument();

        $this->assertFalse($validator->isValid('not a document'));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(['$id' => 'project-1']));
    }

    public function testRejectsEmptyDocument(): void
    {
        $validator = new ProjectDocument();

        $this->assertFalse($validator->isValid(new Document()));
    }

    public function testRejectsMissingId(): void
    {
        $validator = new ProjectDocument();
        $project = new Document(['name' => 'Test']);

        $this->assertFalse($validator->isValid($project));
    }

    public function testReportsObjectType(): void
    {
        $validator = new ProjectDocument();

        $this->assertSame('object', $validator->getType());
        $this->assertFalse($validator->isArray());
    }
}
