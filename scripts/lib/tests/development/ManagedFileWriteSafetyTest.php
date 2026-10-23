<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';
require_once dirname(__DIR__, 2).'/user/integerSetting.php';
require_once dirname(__DIR__, 2).'/network/fireqos.php';
require_once __DIR__.'/../common/TestCase.php';

final class ManagedFileWriteSafetyTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-managed-file-write-']; }

    public function testPinnedPublishRejectsParentOutsideHomeAndDifferentDevice(): void
    {
        $home = $this->pmssEnsureDir($this->tempDir.'/home');
        $inside = $this->pmssEnsureDir($home.'/inside');
        $outside = $this->pmssEnsureDir($this->tempDir.'/outside');
        $stage = $this->pmssWriteFile($this->tempDir.'/stage', 'new');
        $this->pmssWriteFile($outside.'/target', 'kept');
        $device = (int) stat($stage)['dev'];
        foreach ([[$outside, $device], [$inside, $device + 1]] as $scenario) {
            $command = \pmssRootStagePublishCommand($scenario[0], $home, $stage, 'target', $scenario[1]);
            exec($command.' 2>/dev/null', $output, $rc);
            $this->assertTrue($rc !== 0);
            $this->assertSame('new', file_get_contents($stage));
        }
        $this->assertSame('kept', file_get_contents($outside.'/target'));
        $this->assertFalse(file_exists($inside.'/target'));
    }

    public function testPinnedPublishMovesOnlyIntoThePinnedHomeParent(): void
    {
        $home = $this->pmssEnsureDir($this->tempDir.'/home');
        $inside = $this->pmssEnsureDir($home.'/inside');
        $stage = $this->pmssWriteFile($this->tempDir.'/stage', 'new');
        $device = (int) stat($stage)['dev'];
        $command = \pmssRootStagePublishCommand($inside, $home, $stage, 'target', $device);
        exec($command.' 2>/dev/null', $output, $rc);
        $this->assertSame(0, $rc);
        $this->assertSame('new', file_get_contents($inside.'/target'));
        $this->assertFalse(file_exists($stage));
    }

    public function testRootStagePublishesOwnerModeAndRejectsLinkedTargets(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new SkipTest('Root-only staging fixture');
        }
        $account = posix_getpwnam('nobody');
        $group = is_array($account) ? posix_getgrgid($account['gid']) : false;
        if (!is_array($account) || !is_array($group)) throw new SkipTest('Fixture account unavailable');
        $this->pmssWithEnv(['PMSS_HOME_DIR' => $this->tempDir], function () use ($account, $group): void {
            $home = $this->pmssEnsureDir($this->tempDir.'/nobody');
            chown($home, $account['uid']);
            $inside = $this->pmssEnsureDir($home.'/inside');
            chown($inside, $account['uid']);
            $outside = $this->pmssWriteFile($this->tempDir.'/outside', 'kept');
            chmod($outside, 0600);
            $before = lstat($outside);
            $this->assertTrue(\pmssRootStageFileWrite($inside.'/managed', 'payload', $group['name'], 0640));
            $managed = lstat($inside.'/managed');
            $this->assertSame(0, $managed['uid']);
            $this->assertSame($account['gid'], $managed['gid']);
            $this->assertSame(0640, $managed['mode'] & 0777);
            $this->assertSame('payload', file_get_contents($inside.'/managed'));
            $integerPath = $home.'/.trafficLimit';
            $this->assertTrue(\pmssRootStageFileWrite($integerPath, '1', $group['name'], 0640));
            $this->assertTrue(\pmssIntegerSettingFileWrite($integerPath, 2));
            $integer = lstat($integerPath);
            $this->assertSame([0, $account['gid'], 0640, '2'],
                [$integer['uid'], $integer['gid'], $integer['mode'] & 0777, file_get_contents($integerPath)]);
            $throttle = $home.'/.throttle';
            $this->assertTrue(\pmssIntegerSettingFileWrite($throttle, 25));
            $throttleStat = lstat($throttle);
            $this->assertSame(0, $throttleStat['uid']);
            $this->assertSame(0, ($throttleStat['mode'] & 0777) & 0022);
            $state = $this->pmssEnsureDir($this->tempDir.'/traffic-limits');
            $this->pmssWriteFile($state.'/nobody.enabled', '1');
            $this->pmssWithEnv(['PMSS_TRAFFIC_LIMIT_STATE_DIR' => $state], function (): void {
                $caps = \networkFireqosUserCaps(['throttle' => ['max' => 100]], ['nobody']);
                $this->assertSame(25, $caps['nobody']['cap']);
            });
            $accountOwned = $home.'/.account-setting';
            $this->pmssWriteFile($accountOwned, '1');
            chown($accountOwned, $account['uid']);
            chmod($accountOwned, 0600);
            clearstatcache(true, $accountOwned);
            $this->assertTrue(\pmssIntegerSettingFileWrite($accountOwned, 3));
            $this->assertSame($account['uid'], lstat($accountOwned)['uid']);
            $this->assertSame(0600, lstat($accountOwned)['mode'] & 0777);
            symlink($outside, $inside.'/linked');
            $this->assertFalse(\pmssRootStageFileWrite($inside.'/linked', 'new', $group['name'], 0640));
            symlink($inside, $home.'/linked-parent');
            $this->assertFalse(\pmssRootStageFileWrite($home.'/linked-parent/managed', 'new', $group['name'], 0640));
            $outsideDir = $this->pmssEnsureDir($this->tempDir.'/outside-dir/deeper');
            symlink(dirname($outsideDir), $inside.'/linked-intermediate');
            $this->assertFalse(\pmssRootStageFileWrite($inside.'/linked-intermediate/deeper/managed',
                'new', $group['name'], 0640));
            $this->assertFalse(file_exists($outsideDir.'/managed'));
            $this->assertFalse(\pmssRootStageImmutableSet($inside.'/managed',
                ['dev' => $managed['dev'], 'ino' => $managed['ino'] + 1], true));
            $this->assertSame('payload', file_get_contents($inside.'/managed'));
            $after = lstat($outside);
            $this->assertSame([$before['uid'], $before['mode'] & 07777, 'kept'],
                [$after['uid'], $after['mode'] & 07777, file_get_contents($outside)]);
            $this->assertSame([], glob($this->tempDir.'/.pmss-root-stage-*'));
        });
    }

    public function testRootStageSweepRemovesOnlyOldEmptyPrivateDirectories(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new SkipTest('Root-only staging fixture');
        }
        $old = $this->pmssEnsureDir($this->tempDir.'/.pmss-root-stage-old', 0700);
        $nonempty = $this->pmssEnsureDir($this->tempDir.'/.pmss-root-stage-nonempty', 0700);
        $fresh = $this->pmssEnsureDir($this->tempDir.'/.pmss-root-stage-fresh', 0700);
        $wrongMode = $this->pmssEnsureDir($this->tempDir.'/.pmss-root-stage-mode', 0755);
        $this->pmssWriteFile($nonempty.'/file', 'kept');
        foreach ([$old, $nonempty, $wrongMode] as $path) touch($path, time() - 7200);
        \pmssRootStageSweep($this->tempDir);
        $this->assertFalse(file_exists($old));
        foreach ([$nonempty, $fresh, $wrongMode] as $path) $this->assertTrue(is_dir($path));
    }

    public function testEnsureSafeDirTightensAnExistingDirectoryMode(): void
    {
        $path = $this->pmssMakeTempDir('pmss-safe-dir-existing-', 0755);
        chmod($path, 0755);

        $this->assertTrue(\pmssEnsureSafeDir($path, 0751));
        clearstatcache(true, $path);
        $this->assertEquals(0751, fileperms($path) & 0777, 'an existing directory must get the requested mode, not keep its old one');
    }

    public function testNginxUsersDirectoryModeIsAppliedEveryRun(): void
    {
        $setup = (string) file_get_contents(dirname(__DIR__, 2).'/nginxConfig/setup.php');
        $this->assertTrue(strpos($setup, "pmssEnsureSafeDir('/etc/nginx/users', 0751)") !== false, 'nginx users directory mode must be applied on existing hosts too');
    }

    public function testEnsureSafeDirReportsModeFailure(): void
    {
        $root = $this->pmssMakeTempDir('pmss-safe-dir-');
        $library = $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/userFileWrite.php', 'SafeDirModeFixture');
        $result = $this->pmssRunInlinePhpJson(<<<'PHP'
namespace SafeDirModeFixture;
function chmod($path, $mode) {
    return $GLOBALS['failMode'] ? false : \chmod($path, $mode);
}
PHP
            .$library.'$path = '.var_export($root.'/state', true).';'.<<<'PHP'
$GLOBALS['failMode'] = true;
$failed = pmssEnsureSafeDir($path, 0700);
$GLOBALS['failMode'] = false;
$succeeded = pmssEnsureSafeDir($path, 0700);
\clearstatcache(true, $path);
echo json_encode([$failed, $succeeded, \is_dir($path), \fileperms($path) & 0777]);
PHP
        );

        $this->assertSame([false, true, true, 0700], $result);
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

    public function testSerializedTargetRejectsMalformedModesBeforePublishing(): void
    {
        $path = $this->tempDir.'/mode-state.dat';
        file_put_contents($path, 'original');

        foreach (['', 'invalid', '-1', '640junk', "640\n", -1, 010000, str_repeat('9', 30)] as $mode) {
            $failures = [];
            $ok = \pmssManagedSerializedTargetsWrite('replacement', [
                [$path, 'root', $mode, false],
            ], static function (string $failedPath) use (&$failures): void {
                $failures[] = $failedPath;
            });

            $this->assertFalse($ok, (string) $mode);
            $this->assertSame([$path], $failures, (string) $mode);
            $this->assertSame('original', file_get_contents($path), (string) $mode);
        }

        $this->assertSame(640, \pmssManagedSerializedTargetNormalize([$path, 'root', '640', false])[2]);
        $this->assertSame(0640, \pmssManagedSerializedTargetNormalize([$path, 'root', 0640, false])[2]);
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
