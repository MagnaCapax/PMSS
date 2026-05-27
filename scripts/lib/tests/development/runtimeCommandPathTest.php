<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/lib/runtime.php';

class RuntimeCommandPathTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssTrackEnvKeys(['PATH']);
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
        $this->prependCommandPath($binDir);

        $this->assertEquals($binDir.'/pmss-demo-binary', pmssCommandPath('pmss-demo-binary'));
    }

    public function testCommandPathRejectsUnsafeBinaryNamesBeforeShellLookup(): void
    {
        $binDir = $this->pmssMakeExecutableStub('pmss-safe-binary', "#!/bin/sh\nexit 0\n", 'pmss-command-path-');
        $this->prependCommandPath($binDir);

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

    public function testCommandPathRejectsShellBuiltinsWithoutExecutablePaths(): void
    {
        $this->assertEquals('', pmssCommandPath('cd'));
    }

    private function prependCommandPath(string $binDir): void
    {
        $path = getenv('PATH');
        putenv('PATH='.$binDir.($path !== false ? ':'.$path : ''));
    }
}
