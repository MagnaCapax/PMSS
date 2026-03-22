<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RecreateUserSafetyGuardTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__, 4).'/scripts/recreateUser.php';
        $source = @file_get_contents($path);
        $this->assertTrue(is_string($source) && $source !== '', 'Expected to read '.$path);
        return $source;
    }

    public function testRejectsSymlinkedHomeAndBackupPaths(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("pmssRequireSafeRecreateUserPath(\$homeDir, 'home');", $source);
        $this->assertStringContainsString("pmssRequireSafeRecreateUserPath(\$backupDir, 'backup');", $source);
        $this->assertStringContainsString('Refusing to operate on symlinked', $source);
    }

    public function testRejectsUnexpectedResolvedHomePath(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('$realHome = realpath($homeDir);', $source);
        $this->assertStringContainsString('Refusing to operate on unexpected home path', $source);
    }

    public function testEnsureDirChecksMkdirFailure(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('!@mkdir($dir, 0755, true) && !is_dir($dir)', $source);
        $this->assertStringContainsString('Unable to create required directory', $source);
    }

    public function testOwnershipValidationChecksStatFailure(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('$stat = @stat($homeDir);', $source);
        $this->assertStringContainsString('Validation failed: unable to stat homeDir', $source);
    }
}
