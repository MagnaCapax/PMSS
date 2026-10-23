<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userCgroupSliceCleanup.php';

class UserCgroupSliceCleanupTest extends TestCase
{
    private const UID = 2147483646;
    private $messages = [];
    private $reloads = 0;

    protected function setUp(): void
    {
        $this->messages = [];
        $this->reloads = 0;
        $this->assertFalse(posix_getpwuid(self::UID), 'Fixture UID must not resolve');
    }

    private function cleanupSlice(string $root, bool $present = false, bool $dryRun = false, int $rc = 0, ?int $uid = null): bool
    {
        return \pmssUserCgroupSliceDropinsCleanup($uid ?? self::UID, $present, $dryRun,
            $this->pmssMakeArrayLogger($this->messages), function () use ($rc): int {
                $this->reloads++;
                return $rc;
            }, $root, posix_geteuid());
    }

    public function testCleanupPreventsReusedUidFromInheritingEitherNamespace(): void
    {
        $root = $this->pmssMakeTempDir('pmss-slice-reuse-');
        foreach (['system', 'system.control'] as $namespace) {
            $this->pmssWriteFile($root.'/'.$namespace.'/user-'.self::UID.'.slice.d/99-pmss.conf', "[Slice]\nMemoryHigh=4000M\n");
            $this->pmssWriteFile($root.'/'.$namespace.'/user-1234.slice.d/99-pmss.conf', 'other UID');
            $this->pmssWriteFile($root.'/'.$namespace.'/user-.slice.d/99-pmss.conf', 'host-wide');
        }
        $this->assertTrue($this->cleanupSlice($root));
        $this->assertSame(1, $this->reloads);
        foreach (['system', 'system.control'] as $namespace) {
            $dir = $root.'/'.$namespace.'/user-'.self::UID.'.slice.d';
            $this->assertFalse(file_exists($dir));
            $this->assertSame('other UID', file_get_contents($root.'/'.$namespace.'/user-1234.slice.d/99-pmss.conf'));
            $this->assertSame('host-wide', file_get_contents($root.'/'.$namespace.'/user-.slice.d/99-pmss.conf'));
        }
        $this->assertTrue($this->cleanupSlice($root));
        $this->assertSame(1, $this->reloads);
        // Provisioning the reused UID now creates only its own plan file.
        $file = $this->pmssWriteFile($root.'/system.control/user-'.self::UID.'.slice.d/50-MemoryHigh.conf', "[Slice]\nMemoryHigh=12000M\n");
        $this->assertSame([$file], glob(dirname($file).'/*'));
        $this->assertFalse(file_exists($root.'/system/user-'.self::UID.'.slice.d'));
    }

    public function testAccountStillPresentAndDryRunKeepBothDirectories(): void
    {
        $root = $this->pmssMakeTempDir('pmss-slice-retain-');
        $files = [];
        foreach (['system', 'system.control'] as $namespace) {
            $files[] = $this->pmssWriteFile($root.'/'.$namespace.'/user-'.self::UID.'.slice.d/50-MemoryHigh.conf', 'plan');
        }
        $this->assertFalse($this->cleanupSlice($root, true));
        $this->assertTrue($this->cleanupSlice($root, true, true));
        foreach ($files as $file) $this->assertSame('plan', file_get_contents($file));
        $this->assertSame(0, $this->reloads);
    }

    public function testUidZeroNegativeAndReassignedUidAreRefused(): void
    {
        $root = $this->pmssMakeTempDir('pmss-slice-active-');
        foreach ([0, -1, posix_geteuid()] as $uid) {
            $file = $this->pmssWriteFile($root.'/system/user-'.$uid.'.slice.d/99-pmss.conf', 'keep');
            $this->assertFalse($this->cleanupSlice($root, false, false, 0, $uid));
            $this->assertSame('keep', file_get_contents($file));
        }
        $this->assertSame(0, $this->reloads);
    }

    public function testSymlinksAndSpecialFilesRefuseWholeDirectoryBeforeDeleting(): void
    {
        foreach (['file-link', 'dir-link', 'base-link', 'root-link', 'fifo', 'directory'] as $kind) {
            $root = $this->pmssMakeTempDir('pmss-slice-clean-unsafe-');
            $dir = $this->pmssEnsureDir($root.'/system/user-'.self::UID.'.slice.d');
            $file = $this->pmssWriteFile($dir.'/40-regular.conf', 'preserved');
            $outside = $this->pmssWriteFile($root.'/outside', 'outside');
            if ($kind === 'file-link') symlink($outside, $dir.'/99-link.conf');
            if ($kind === 'fifo') posix_mkfifo($dir.'/99-fifo.conf', 0600);
            if ($kind === 'directory') mkdir($dir.'/nested');
            if ($kind === 'dir-link') { rename($dir, $root.'/real'); symlink($root.'/real', $dir); }
            if ($kind === 'base-link') { rename($root.'/system', $root.'/real'); symlink($root.'/real', $root.'/system'); }
            if ($kind === 'root-link') { symlink($root, $root.'/alias'); $root .= '/alias'; }
            $this->assertFalse($this->cleanupSlice($root), $kind);
            $this->assertSame('preserved', file_get_contents($file));
            $this->assertSame('outside', file_get_contents($outside));
        }
        $this->assertSame(0, $this->reloads);
    }

    public function testFailedReloadReturnsFailureAfterRemoval(): void
    {
        $root = $this->pmssMakeTempDir('pmss-slice-clean-reload-');
        $file = $this->pmssWriteFile($root.'/system/user-'.self::UID.'.slice.d/99-pmss.conf', 'old');
        $this->assertFalse($this->cleanupSlice($root, false, false, 9));
        $this->assertFalse(file_exists(dirname($file)));
        $this->assertSame(1, $this->reloads);
        $this->assertTrue($this->pmssMessagesContain($this->messages, 'daemon-reload failed'));
    }

    public function testUnsafeOneNamespaceStillReloadsSuccessfulCleanupOfOther(): void
    {
        $root = $this->pmssMakeTempDir('pmss-slice-clean-partial-');
        $safe = $this->pmssWriteFile($root.'/system/user-'.self::UID.'.slice.d/40-pmss-user-legacy.conf', 'old');
        $unsafe = $this->pmssWriteFile($root.'/system.control/user-'.self::UID.'.slice.d/50-MemoryHigh.conf', 'keep');
        chmod($unsafe, 0666);
        $this->assertFalse($this->cleanupSlice($root));
        $this->assertFalse(file_exists(dirname($safe)));
        $this->assertSame('keep', file_get_contents($unsafe));
        $this->assertSame(1, $this->reloads);
    }

    public function testWiringRunsCleanupAfterFinalUserdelAndReportsFailure(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/terminateUser.php', [
            'pmssTerminateUserFinalRemovalSteps($username, $uid, $killUserCommand)',
            'pmssUserCgroupSliceDropinsCleanup(', '$uid, pmssUserAccountLookup($username) !== null, $dryRun',
            '## Incomplete: remove_slice_dropins',
        ]);
    }
}
