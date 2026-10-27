<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userCgroupSliceDropins.php';

class UserCgroupSliceDropinsTest extends TestCase
{
    private $messages = [];
    private $reloads = 0;

    protected function setUp(): void
    {
        $this->messages = [];
        $this->reloads = 0;
    }

    private function repair(string $base, int $reloadRc = 0, ?int $owner = null): bool
    {
        return \pmssUserCgroupSliceDropinsRepair($this->pmssMakeArrayLogger($this->messages), function () use ($reloadRc): int {
            $this->reloads++;
            return $reloadRc;
        }, $base, $owner ?? posix_geteuid());
    }

    public function testBatchPreservesBytesBackupsAndLegacyOrderBeforeCanonicalProperties(): void
    {
        $root = $this->pmssMakeTempDir('pmss-slice-demote-');
        $base = $this->pmssEnsureDir($root.'/system');
        $contents = ['90-pmss-user.conf' => "[Slice]\nCPUWeight=500\nMemoryHigh=4000M\nCPUAccounting=yes\n",
            '99-pmss.conf' => "[Slice]\nIOWeight=500\nTasksAccounting=yes\n"];
        foreach ([1234, 2345] as $uid) {
            $this->pmssWriteFiles($base.'/user-'.$uid.'.slice.d', $contents);
        }
        $canonical = $this->pmssWriteFile($root.'/system.control/user-1234.slice.d/50-CPUWeight.conf', "[Slice]\nCPUWeight=200\n");
        chmod($base.'/user-1234.slice.d/90-pmss-user.conf', 0600);
        $reloads = 0;
        $this->assertTrue(\pmssUserCgroupSliceDropinsRepair($this->pmssMakeArrayLogger($this->messages),
            function () use ($base, &$reloads): int {
                $reloads++;
                foreach ([1234, 2345] as $uid) {
                    $this->assertFalse(file_exists($base.'/user-'.$uid.'.slice.d/90-pmss-user.conf'));
                    $this->assertFalse(file_exists($base.'/user-'.$uid.'.slice.d/99-pmss.conf'));
                }
                return 0;
            }, $base, posix_geteuid()));
        $this->assertSame(1, $reloads);
        foreach ([1234, 2345] as $uid) {
            $dir = $base.'/user-'.$uid.'.slice.d';
            $this->assertSame($contents['90-pmss-user.conf'], file_get_contents($dir.'/40-pmss-user-legacy.conf'));
            $this->assertSame($contents['99-pmss.conf'], file_get_contents($dir.'/41-pmss-legacy.conf'));
            foreach ($contents as $name => $body) {
                $backups = glob($dir.'/'.$name.'.pmss-backup-*');
                $this->assertSame(1, count($backups));
                $this->assertSame($body, file_get_contents($backups[0]));
            }
        }
        $this->assertSame(0600, fileperms($base.'/user-1234.slice.d/40-pmss-user-legacy.conf') & 0777);
        $this->assertSame("[Slice]\nCPUWeight=200\n", file_get_contents($canonical));
        $names = array_map('basename', array_merge(glob($base.'/user-1234.slice.d/*.conf'), [$canonical]));
        sort($names);
        $this->assertSame(['40-pmss-user-legacy.conf', '41-pmss-legacy.conf', '50-CPUWeight.conf'], $names);
    }

    public function testHostWideAndNonUidDirectoriesAreUntouched(): void
    {
        $base = $this->pmssMakeTempDir('pmss-slice-exact-');
        foreach (['user-', 'user-1234x', 'user-01234', 'user--1234', 'user-1234.slice.d-extra'] as $name) {
            $file = $base.'/'.$name.(strpos($name, '.slice') === false ? '.slice.d' : '').'/99-pmss.conf';
            $this->pmssWriteFile($file, 'untouched');
        }
        $this->assertFalse($this->repair($base));
        foreach (glob($base.'/*/99-pmss.conf') as $file) $this->assertSame('untouched', file_get_contents($file));
        $this->assertSame(0, $this->reloads);
    }

    public function testRerunDoesNotCreateBackupsOrReload(): void
    {
        $base = $this->pmssMakeTempDir('pmss-slice-repeat-');
        $this->pmssWriteFile($base.'/user-1234.slice.d/99-pmss.conf', '[Slice]');
        $this->assertTrue($this->repair($base));
        $paths = glob($base.'/user-1234.slice.d/*');
        $this->assertFalse($this->repair($base));
        $this->assertSame($paths, glob($base.'/user-1234.slice.d/*'));
        $this->assertSame(1, $this->reloads);
    }

    public function testRefusesSymlinksSpecialFilesAndOccupiedDestinations(): void
    {
        foreach (['source-link', 'dir-link', 'ancestor-link', 'fifo', 'directory', 'target-link', 'target-file'] as $kind) {
            $base = $this->pmssMakeTempDir('pmss-slice-unsafe-');
            $dir = $this->pmssEnsureDir($base.'/user-1234.slice.d');
            $outside = $this->pmssWriteFile($base.'/outside', 'unchanged');
            $source = $dir.'/90-pmss-user.conf';
            if ($kind === 'source-link') symlink($outside, $source);
            elseif ($kind === 'fifo') posix_mkfifo($source, 0600);
            elseif ($kind === 'directory') mkdir($source);
            else file_put_contents($source, 'legacy');
            if ($kind === 'dir-link') { rename($dir, $base.'/real'); symlink($base.'/real', $dir); }
            if ($kind === 'ancestor-link') { symlink($base, $base.'/alias'); $base .= '/alias'; }
            if ($kind === 'target-link') symlink($outside, $dir.'/40-pmss-user-legacy.conf');
            if ($kind === 'target-file') file_put_contents($dir.'/40-pmss-user-legacy.conf', 'occupied');
            $this->assertFalse($this->repair($base), $kind);
            $this->assertSame('unchanged', file_get_contents($outside));
            $this->assertSame([], glob($dir.'/*.pmss-backup-*'));
            $this->assertTrue(file_exists($source));
        }
        $this->assertSame(0, $this->reloads);
    }

    public function testRefusesWrongOwnerAndWritableFilesOrDirectories(): void
    {
        foreach (['owner', 'file-mode', 'dir-mode'] as $kind) {
            $base = $this->pmssMakeTempDir('pmss-slice-owner-');
            $file = $this->pmssWriteFile($base.'/user-1234.slice.d/99-pmss.conf', 'legacy');
            if ($kind === 'file-mode') chmod($file, 0666);
            if ($kind === 'dir-mode') chmod(dirname($file), 0777);
            $this->assertFalse($this->repair($base, 0, posix_geteuid() + ($kind === 'owner' ? 1 : 0)));
            $this->assertSame('legacy', file_get_contents($file));
        }
    }

    public function testBackupFailureRetainsSourceAndReloadFailureIsReported(): void
    {
        $base = $this->pmssMakeTempDir('pmss-slice-backup-');
        $file = $this->pmssWriteFile($base.'/user-1234.slice.d/99-pmss.conf', 'legacy');
        $timestamp = date('YmdHis');
        for ($i = 0; $i < 10; $i++) file_put_contents(\pmssManagedPathBackupCandidate($file, $timestamp, $i), 'occupied');
        $this->assertFalse($this->repair($base));
        $this->assertSame('legacy', file_get_contents($file));
        $this->assertSame(0, $this->reloads);
        $this->pmssWriteFile($base.'/user-2345.slice.d/99-pmss.conf', 'legacy');
        $this->assertFalse($this->repair($base, 7));
        $this->assertSame(1, $this->reloads);
        $this->assertTrue($this->pmssMessagesContain($this->messages, 'daemon-reload failed'));
    }

    public function testDryRunRetainsSourceAndDoesNotReload(): void
    {
        $base = $this->pmssMakeTempDir('pmss-slice-dry-');
        $file = $this->pmssWriteFile($base.'/user-1234.slice.d/90-pmss-user.conf', 'legacy');
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function () use ($base): void {
            $this->assertFalse($this->repair($base));
        });
        $this->assertSame('legacy', file_get_contents($file));
        $this->assertSame(0, $this->reloads);
    }
}
