<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';
require_once __DIR__.'/../common/TestCase.php';

final class ManagedFileWriteSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-managed-file-write-');
    }

    public function testImmutableToggleRejectsNulPathBeforeFilesystemProbe(): void
    {
        \pmssManagedFileImmutableSet($this->tempDir."/bad\0file", true);

        $this->assertTrue(true, 'NUL path should be rejected before filesystem calls throw');
    }

    public function testSerializedTargetRejectsNulPathBeforeImmutableToggle(): void
    {
        $failures = [];

        $ok = \pmssManagedSerializedTargetsWrite('payload', [
            [$this->tempDir."/bad\0state", 'root', 0640, true],
        ], static function (string $path) use (&$failures): void {
            $failures[] = $path;
        });

        $this->assertFalse($ok);
        $this->assertSame(['(invalid target)'], $failures);
    }

    public function testSerializedTargetRejectsMalformedTupleWithoutWriting(): void
    {
        $path = $this->tempDir.'/state.dat';
        $failures = [];

        $ok = \pmssManagedSerializedTargetsWrite('payload', [
            [$path],
        ], static function (string $path) use (&$failures): void {
            $failures[] = $path;
        });

        $this->assertFalse($ok);
        $this->assertSame([$path], $failures);
        $this->assertFalse(file_exists($path));
    }

    public function testSerializedTargetStillWritesValidTarget(): void
    {
        $path = $this->tempDir.'/state.dat';
        $failures = [];

        $ok = \pmssManagedSerializedTargetsWrite('payload', [
            [$path, 'root', 0640, false],
        ], static function (string $path) use (&$failures): void {
            $failures[] = $path;
        });

        $this->assertTrue($ok);
        $this->assertSame([], $failures);
        $this->assertSame('payload', (string) file_get_contents($path));
    }
}
