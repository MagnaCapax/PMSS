<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userLifecycle.php';

class UserLifecycleStringableStub
{
    public function __toString(): string
    {
        return "hello\nworld";
    }
}

class userLifecycleLoggingTest extends TestCase
{
    public function testFormatTextFieldLeavesPlainTextUntouched(): void
    {
        $this->assertEquals('plain text', \pmssUserLifecycleFormatTextField('plain text'));
    }

    public function testFormatTextFieldCollapsesControlCharacters(): void
    {
        $this->assertEquals('hello world line', \pmssUserLifecycleFormatTextField("hello\r\nworld\tline"));
    }

    public function testFormatTextFieldNormalizesNonStringScalars(): void
    {
        $this->assertEquals('42', \pmssUserLifecycleFormatTextField(42));
        $this->assertEquals('true', \pmssUserLifecycleFormatTextField(true));
        $this->assertEquals('false', \pmssUserLifecycleFormatTextField(false));
    }

    public function testFormatTextFieldHandlesNullAndArraySafely(): void
    {
        $this->assertEquals('', \pmssUserLifecycleFormatTextField(null));
        $this->assertEquals('array', \pmssUserLifecycleFormatTextField(['nested' => 'value']));
    }

    public function testFormatTextFieldUsesStringableObjects(): void
    {
        $this->assertEquals('hello world', \pmssUserLifecycleFormatTextField(new UserLifecycleStringableStub()));
    }

    public function testUserLifecycleWriterUsesFormattingHelperForTextLogFields(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/userLifecycle.php');

        $this->assertStringContainsString("pmssUserLifecycleFormatTextField(\$payload['status'] ?? 'INFO')", $source);
        $this->assertStringContainsString("pmssUserLifecycleFormatTextField(\$payload['action'] ?? 'unknown')", $source);
        $this->assertStringContainsString("pmssUserLifecycleFormatTextField(\$payload['phase'] ?? 'unknown')", $source);
        $this->assertStringContainsString("pmssUserLifecycleFormatTextField(\$payload['username'] ?? '')", $source);
        $this->assertStringContainsString("pmssUserLifecycleFormatTextField(\$payload['message'])", $source);
        $this->assertStringContainsString("pmssUserLifecycleFormatTextField(\$payload['step'])", $source);
    }

    public function testContextLogHelperDelegatesToBaseContextAndWriter(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/userLifecycle.php');

        $this->assertStringContainsString('function pmssUserLifecycleContextLog(', $source);
        $this->assertStringContainsString('pmssUserWriteLogs(pmssUserBaseContext($action, $phase, $username, $extra));', $source);
    }
}
