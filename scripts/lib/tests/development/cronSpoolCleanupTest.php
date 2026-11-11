<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/cronSpool.php';

/** Model orphans with fixed lookup results, without changing accounts or file ownership. */
class CronSpoolCleanupTest extends TestCase
{
    public function testOrphanRemovalAndRepeatedPass(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-cron-spool-');
        $path = $this->pmssWriteFile($dir.'/fixtur01', 'private former jobs');
        $uid = fileowner($path);
        $this->assertSame(array('status' => 'removed', 'uid' => $uid),
            \pmssUserCronSpoolRemoveOrphan($path, self::pmssMakeCallbacks(['lookup' => false])['lookup']));
        $this->assertFalse(file_exists($path));
        $this->assertSame('absent', \pmssUserCronSpoolRemoveOrphan($path, self::pmssMakeCallbacks(['lookup' => false])['lookup'])['status']);
    }

    public function testAnyLiveUidPreservesContentsAndMetadata(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-cron-spool-');
        $path = $this->pmssWriteFile($dir.'/fixtur01', 'keep jobs');
        $before = lstat($path);
        $lookup = static function (int $uid): array { return array('uid' => $uid, 'name' => 'fixtur02'); };
        $this->assertSame('claimed', \pmssUserCronSpoolRemoveOrphan($path, $lookup)['status']);
        $this->assertSame($before, lstat($path));
        $this->assertSame('keep jobs', file_get_contents($path));
    }

    public function testNonFalseLookupResultsFailClosed(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-cron-spool-');
        $path = $this->pmssWriteFile($dir.'/fixtur01', 'keep jobs');
        foreach (array(null, array(), true, 0) as $value) {
            $lookup = self::pmssMakeCallbacks(['lookup' => $value])['lookup'];
            $this->assertSame('claimed', \pmssUserCronSpoolRemoveOrphan($path, $lookup)['status']);
            $this->assertTrue(file_exists($path));
        }
    }

    public function testUnsafePathsAndEntriesArePreserved(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-cron-spool-');
        $target = $this->pmssWriteFile($dir.'/fixtur01', 'keep jobs');
        mkdir($dir.'/folder');
        symlink($target, $dir.'/link');
        symlink($dir.'/folder', $dir.'/parent');
        $this->pmssWriteFile($dir.'/folder/fixtur02', 'keep nested jobs');
        foreach (array('', '../fixtur01', $target."\0suffix", $dir.'/folder', $dir.'/link',
            $dir.'/parent/fixtur02', $dir.'/folder/../fixtur01') as $path) {
            $this->assertSame('unsafe', \pmssUserCronSpoolRemoveOrphan($path, self::pmssMakeCallbacks(['lookup' => false])['lookup'])['status']);
        }
        $this->assertSame('keep jobs', file_get_contents($target));
        $this->assertTrue(is_link($dir.'/link'));
        $this->assertSame('keep nested jobs', file_get_contents($dir.'/folder/fixtur02'));
    }

    public function testReplacedAndDisappearingEntriesAreNotRemoved(): void
    {
        foreach (array('replace', 'symlink', 'absent') as $change) {
            $dir = $this->pmssMakeTempDir('pmss-cron-spool-');
            $path = $this->pmssWriteFile($dir.'/fixtur01', 'old jobs');
            $lookup = function (int $uid) use ($path, $change): bool {
                rename($path, $path.'.old');
                if ($change === 'replace') $this->pmssWriteFile($path, 'new jobs');
                if ($change === 'symlink') symlink($path.'.old', $path);
                return false;
            };
            $this->assertSame('changed', \pmssUserCronSpoolRemoveOrphan($path, $lookup)['status']);
            $this->assertSame('old jobs', file_get_contents($path.'.old'));
            if ($change === 'replace') $this->assertSame('new jobs', file_get_contents($path));
            if ($change === 'symlink') $this->assertTrue(is_link($path));
        }
    }

    public function testDryRunPreservesOrphan(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-cron-spool-');
        $path = $this->pmssWriteFile($dir.'/fixtur01', 'private former jobs');
        $this->assertSame('would_remove', \pmssUserCronSpoolRemoveOrphan($path, self::pmssMakeCallbacks(['lookup' => false])['lookup'], true)['status']);
        $this->assertSame('private former jobs', file_get_contents($path));
    }

    public function testSweepAuditsMetadataOnlyAndIsIdempotent(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-cron-spool-');
        $path = $this->pmssWriteFile($dir.'/fixtur01', 'PRIVATE-JOB-CONTENT');
        $uid = fileowner($path);
        $records = array();
        $log = static function (array $record) use (&$records): void { $records[] = $record; };
        $this->assertTrue(\pmssUserCronSpoolSweep($dir, $log, self::pmssMakeCallbacks(['lookup' => false])['lookup']));
        $this->assertSame(array(array('user' => 'fixtur01', 'path' => $path, 'status' => 'removed', 'uid' => $uid)), $records);
        $this->assertTrue(\pmssUserCronSpoolSweep($dir, $log, self::pmssMakeCallbacks(['lookup' => false])['lookup']));
        $this->assertSame(1, count($records));
    }

    public function testSweepPreservesClaimedFilesSilently(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-cron-spool-');
        $path = $this->pmssWriteFile($dir.'/fixtur01', 'keep jobs');
        $records = array();
        $log = static function (array $record) use (&$records): void { $records[] = $record; };
        $lookup = static function (int $uid): array { return array('uid' => $uid); };
        $this->assertTrue(\pmssUserCronSpoolSweep($dir, $log, $lookup));
        $this->assertSame(array(), $records);
        $this->assertSame('keep jobs', file_get_contents($path));
    }

    public function testSweepContinuesPastUnsafeEntriesAndReportsFailure(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-cron-spool-');
        mkdir($dir.'/fixtur01');
        $path = $this->pmssWriteFile($dir.'/fixtur02', 'old jobs');
        $records = array();
        $log = static function (array $record) use (&$records): void { $records[] = $record; };
        $this->assertFalse(\pmssUserCronSpoolSweep($dir, $log, self::pmssMakeCallbacks(['lookup' => false])['lookup']));
        $this->assertSame(array('unsafe', 'removed'), array_column($records, 'status'));
        $this->assertFalse(file_exists($path));
        $this->assertTrue(is_dir($dir.'/fixtur01'));
    }

    public function testSweepDryRunAndUnavailableDirectories(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-cron-spool-');
        $path = $this->pmssWriteFile($dir.'/fixtur01', 'old jobs');
        $records = array();
        $log = static function (array $record) use (&$records): void { $records[] = $record; };
        $this->assertTrue(\pmssUserCronSpoolSweep($dir, $log, self::pmssMakeCallbacks(['lookup' => false])['lookup'], true));
        $this->assertSame('would_remove', $records[0]['status']);
        $this->assertTrue(file_exists($path));
        $link = $this->pmssMakeTempDir('pmss-cron-spool-link-').'/spool';
        symlink($dir, $link);
        foreach (array($dir.'/missing', $path, $link) as $unavailable) {
            $this->assertFalse(\pmssUserCronSpoolSweep($unavailable, $log, self::pmssMakeCallbacks(['lookup' => false])['lookup']));
        }
        $this->assertSame(array('would_remove', 'scan_failed', 'scan_failed', 'scan_failed'), array_column($records, 'status'));
    }

    public function testRootCronAndProvisioningUseSharedGuard(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/cronSpoolCleanup.php', array(
            'posix_geteuid() !== 0', "pmssCronLockAcquire('cronSpoolCleanup')", "posix_getpwuid(0) === false",
            'pmssUserCronSpoolSweep(', "pmssReadRegularFileContents('/etc/passwd')", "in_array('--dry-run',",
        ));
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/add/preflight.php', array(
            'pmssUsernameIsValidForCreate($userName)', 'pmssUserCronSpoolRemoveOrphan(',
        ));
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/root.cron',
            '23 * * * *   root    sleep 35; /scripts/cron/cronSpoolCleanup.php >> /var/log/pmss/cronSpoolCleanup.log 2>&1');
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/template.logrotate.pmss', '/var/log/pmss/cronSpoolCleanup.log');
    }
}
