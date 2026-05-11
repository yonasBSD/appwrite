<?php

namespace Tests\Unit\Vcs\Validator;

use Appwrite\Vcs\Validator\CommitSkipPatterns;
use PHPUnit\Framework\TestCase;

class CommitSkipPatternsTest extends TestCase
{
    public function testKnownSkipDirectivesSkip(): void
    {
        $validator = new CommitSkipPatterns();

        $this->assertFalse($validator->isValid('[skip ci] update changelog'));
        $this->assertFalse($validator->isValid('[ci skip] update changelog'));
        $this->assertFalse($validator->isValid('[no ci] update changelog'));
        $this->assertFalse($validator->isValid('[skip action] update changelog'));
        $this->assertFalse($validator->isValid('[action skip] update changelog'));
        $this->assertFalse($validator->isValid('[no action] update changelog'));
        $this->assertFalse($validator->isValid('[skip actions] update changelog'));
        $this->assertFalse($validator->isValid('[actions skip] update changelog'));
        $this->assertFalse($validator->isValid('[no actions] update changelog'));
        $this->assertFalse($validator->isValid('[skip deploy] update changelog'));
        $this->assertFalse($validator->isValid('[deploy skip] update changelog'));
        $this->assertFalse($validator->isValid('[no deploy] update changelog'));
        $this->assertFalse($validator->isValid('[skip appwrite] update changelog'));
        $this->assertFalse($validator->isValid('[appwrite skip] update changelog'));
        $this->assertFalse($validator->isValid('[no appwrite] update changelog'));
    }

    public function testKnownSkipDirectivesAreCaseInsensitive(): void
    {
        $validator = new CommitSkipPatterns();

        $this->assertFalse($validator->isValid('[SKIP CI] update changelog'));
        $this->assertFalse($validator->isValid('[Skip Deploy] update changelog'));
        $this->assertFalse($validator->isValid('[SKIP APPWRITE] update changelog'));
        $this->assertFalse($validator->isValid('[Appwrite Skip] update changelog'));
        $this->assertFalse($validator->isValid('[No Actions] update changelog'));
    }

    public function testMessageWithoutKnownDirectiveProceeds(): void
    {
        $validator = new CommitSkipPatterns();

        $this->assertTrue($validator->isValid('fix: real bug fix'));
        $this->assertTrue($validator->isValid('feat: add new feature'));
        $this->assertTrue($validator->isValid('skip deploy without brackets'));
        $this->assertTrue($validator->isValid('deploy this please'));
        $this->assertTrue($validator->isValid('skip-checks:true'));
    }

    public function testDirectiveMustBeStandalone(): void
    {
        $validator = new CommitSkipPatterns();

        $this->assertFalse($validator->isValid('docs: update readme [skip deploy]'));
        $this->assertTrue($validator->isValid('docs: update readme[skip deploy]'));
        $this->assertTrue($validator->isValid('prefix[skip deploy]suffix'));
        $this->assertTrue($validator->isValid('refactor: skip appwrite cache seeding'));
        $this->assertTrue($validator->isValid('fix: appwrite skip quota check in tests'));
    }

    public function testMultilineCommitMessageSkips(): void
    {
        $validator = new CommitSkipPatterns();
        $message = "feat: add new stuff\n\nMore detail here.\n\n[skip deploy]";

        $this->assertFalse($validator->isValid($message));
    }

    public function testWhitespaceInsideDirectiveIsNormalized(): void
    {
        $validator = new CommitSkipPatterns();

        $this->assertFalse($validator->isValid('[skip   deploy] docs only'));
        $this->assertFalse($validator->isValid('[no   actions] docs only'));
    }

    public function testNonStringCommitMessageProceeds(): void
    {
        $validator = new CommitSkipPatterns();

        $this->assertTrue($validator->isValid(null));
        $this->assertTrue($validator->isValid([]));
    }
}
