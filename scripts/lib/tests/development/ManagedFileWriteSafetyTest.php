<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';
require_once __DIR__.'/../common/TestCase.php';

final class ManagedFileWriteSafetyTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-managed-file-write-']; }

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

    public function testAtomicJsonPublicationFailuresPreservePreviousSnapshot(): void
    {
        foreach (['encoding', 'temp', 'false', 'zero', 'short', 'chmod', 'rename'] as $mode) {
            $result = $this->atomicJsonWriteFixture($mode);
            $this->assertSame(false, $result['result'], $mode);
            $this->assertSame('previous snapshot', $result['bytes'], $mode);
            $this->assertSame([], $result['temporaryFiles'], $mode);
            $this->assertSame(null, $result['exception'], $mode);
            $this->assertSame($mode === 'encoding' ? 0 : 1, $result['tempCalls'], $mode);
        }
    }

    public function testAtomicJsonPublicationExceptionsCleanTemporaryFiles(): void
    {
        foreach (['writeThrow', 'chmodThrow', 'renameThrow', 'writeError'] as $mode) {
            $result = $this->atomicJsonWriteFixture($mode);
            $this->assertSame(null, $result['result'], $mode);
            $this->assertSame(true, $result['sameThrowable'], $mode);
            $this->assertSame($mode, $result['exception'], $mode);
            $this->assertSame('previous snapshot', $result['bytes'], $mode);
            $this->assertSame([], $result['temporaryFiles'], $mode);
        }
    }

    public function testAtomicJsonPublicationPreservesSuccessfulBytesAndMode(): void
    {
        $result = $this->atomicJsonWriteFixture('success');
        $this->assertSame(true, $result['result']);
        $this->assertSame(\pmssJsonEncodePrettyLine(['state' => 'healthy']), $result['bytes']);
        $this->assertSame(0644, $result['permissions']);
        $this->assertSame([], $result['temporaryFiles']);
        $this->assertSame(null, $result['exception']);
    }

    private function atomicJsonWriteFixture(string $mode): array
    {
        $root = $this->pmssMakeTempDir('atomic-json-write-');
        $script = <<<'PHP'
namespace AtomicJsonWriteFixture;
PHP;
        $script .= $this->pmssInlinePhpAtomicPublicationShims('temp');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/userFileWrite.php', 'AtomicJsonWriteFixture');
        $script .= <<<'PHP'
$GLOBALS['mode'] = getenv('PMSS_TEST_STATUS_MODE');
$GLOBALS['tempCalls'] = 0;
$GLOBALS['throwable'] = $GLOBALS['mode'] === 'writeError'
    ? new \Error($GLOBALS['mode']) : new \RuntimeException($GLOBALS['mode']);
$path = getenv('PMSS_TEST_STATUS_ROOT').'/status.json';
\file_put_contents($path, 'previous snapshot');
$status = ['state' => $GLOBALS['mode'] === 'encoding' ? "\xff" : 'healthy'];
$result = $exception = null;
$sameThrowable = false;
try {
    $result = pmssAtomicJsonFileWrite($path, $status, 0644);
} catch (\Throwable $caught) {
    $exception = $caught->getMessage();
    $sameThrowable = $caught === $GLOBALS['throwable'];
}
clearstatcache();
echo json_encode(['result' => $result, 'exception' => $exception, 'sameThrowable' => $sameThrowable,
    'bytes' => \file_get_contents($path), 'permissions' => fileperms($path) & 0777,
    'temporaryFiles' => glob($path.'.pmss-tmp-*'), 'tempCalls' => $GLOBALS['tempCalls']]);
PHP;
        return $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_STATUS_MODE' => $mode,
            'PMSS_TEST_STATUS_ROOT' => $root,
        ]);
    }
}
