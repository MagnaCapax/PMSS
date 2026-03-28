<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/lib/runtime.php';

class RuntimeCommandPathTest extends TestCase
{
    /** @var string|false */
    private $previousPath;

    protected function setUp(): void
    {
        $this->previousPath = getenv('PATH');
    }

    protected function tearDown(): void
    {
        if ($this->previousPath === false) {
            putenv('PATH');
            return;
        }

        putenv('PATH='.$this->previousPath);
    }

    public function testCommandBinaryNameIsSafeAcceptsSimpleBinaryNames(): void
    {
        $this->assertTrue(pmssCommandBinaryNameIsSafe('php'));
        $this->assertTrue(pmssCommandBinaryNameIsSafe('python3.11'));
        $this->assertTrue(pmssCommandBinaryNameIsSafe('seedbox-helper_test+1'));
    }

    public function testCommandBinaryNameIsSafeRejectsUnsafeNames(): void
    {
        $this->assertTrue(pmssCommandBinaryNameIsSafe('') === false);
        $this->assertTrue(pmssCommandBinaryNameIsSafe('two words') === false);
        $this->assertTrue(pmssCommandBinaryNameIsSafe('../php') === false);
        $this->assertTrue(pmssCommandBinaryNameIsSafe('php;id') === false);
        $this->assertTrue(pmssCommandBinaryNameIsSafe("php\nls") === false);
    }

    public function testCommandPathReturnsStubPathForSafeBinary(): void
    {
        $binDir = $this->pmssMakeExecutableStub('pmss-demo-binary', "#!/bin/sh\nexit 0\n", 'pmss-command-path-');
        putenv('PATH='.$binDir.($this->previousPath !== false ? ':'.$this->previousPath : ''));

        $this->assertEquals($binDir.'/pmss-demo-binary', pmssCommandPath('pmss-demo-binary'));
    }

    public function testCommandPathRejectsUnsafeBinaryNamesBeforeShellLookup(): void
    {
        $binDir = $this->pmssMakeExecutableStub('pmss-safe-binary', "#!/bin/sh\nexit 0\n", 'pmss-command-path-');
        putenv('PATH='.$binDir.($this->previousPath !== false ? ':'.$this->previousPath : ''));

        $this->assertEquals('', pmssCommandPath('../pmss-safe-binary'));
        $this->assertEquals('', pmssCommandPath('pmss-safe-binary;id'));
    }

    public function testCommandPathReturnsEmptyStringWhenBinaryMissing(): void
    {
        putenv('PATH=/nonexistent');

        $this->assertEquals('', pmssCommandPath('pmss-missing-binary'));
    }

    public function testCommandPathReturnsEmptyStringForBlankInput(): void
    {
        $this->assertEquals('', pmssCommandPath('   '));
    }
}
