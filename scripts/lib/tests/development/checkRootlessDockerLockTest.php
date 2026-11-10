<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Prevent Docker daemons from retaining their watchdog's cron lock (Refs #949). */
class CheckRootlessDockerLockTest extends TestCase
{
    public function testLockExportPrecedesEveryDockerCommand(): void
    {
        $this->assertOrderedStrings([
            "\$checkRootlessDockerLock = pmssCronLockAcquire('checkRootlessDocker');",
            'pmssLockHandleExportChildCloseFds($checkRootlessDockerLock);',
            'runCommand(',
        ], $this->pmssReadRepoFile('scripts/cron/checkRootlessDocker.php'));
    }

    public function testCronSetupExportsTheHeldLockAndRunCommandClosesItInChildren(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-rootless-lock-');
        $report = $directory.'/child-fds';
        $source = $this->pmssReadRepoFile('scripts/cron/checkRootlessDocker.php');
        // Execute only the real lock setup, without cron logging or account/daemon actions.
        $this->assertSame(1, preg_match('/\$checkRootlessDockerLock = .*?(?=\$logger =)/s', $source, $setup));
        $script = 'require '.var_export($this->pmssRepoPath('scripts/lib/runtime.php'), true).'; '
            .$setup[0]
            .'$fds = pmssLockHandleFdList($checkRootlessDockerLock); '
            .'$exported = getenv(PMSS_UPDATE_LOCK_FDS_ENV); '
            .'$rc = runCommand('.var_export('ls -l /proc/$$/fd > '.escapeshellarg($report), true)
            .', false, static function ($message): void {}); '
            .'$path = pmssRuntimeLockPath("pmss-checkRootlessDocker.lock"); '
            .'$contender = pmssLockFileAcquire($path, true); '
            .'echo json_encode([$fds, $exported, $rc, $contender === false, is_resource($checkRootlessDockerLock)]); '
            .'pmssLockHandleRelease($contender); pmssLockHandleRelease($checkRootlessDockerLock);';
        $result = $this->pmssExecInlinePhp($script, [
            'PMSS_RUNTIME_LOCK_DIR' => $directory.'/locks',
            'PMSS_UPDATE_LOCK_FDS' => null,
            'PMSS_RUNCOMMAND_DEBUG' => null,
        ]);

        $this->assertSame(0, $result['rc']);
        [$fds, $exported, $rc, $busy, $held] = $this->pmssDecodeJsonArray($result['output']);
        $this->assertTrue($fds !== [], 'The fixture must expose an inheritable lock descriptor');
        $this->assertSame(implode(',', $fds), $exported, 'Cron setup must export the acquired lock');
        $this->assertSame(0, $rc);
        $this->assertTrue($busy && $held, 'The parent must retain exclusive ownership after child execution');
        $childFds = (string) file_get_contents($report);
        $this->assertTrue($childFds !== '', 'The child descriptor probe must produce output');
        $this->assertStringNotContainsString($directory.'/locks/pmss-checkRootlessDocker.lock', $childFds);
    }
}
