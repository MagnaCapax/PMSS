<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/sessionHistoryPermissions.php';

class ProcessArchiveDirPermissionsTest extends TestCase
{
    /** Create the archive directory under the fixture root with an exact mode. */
    private function makeDir(string $root, int $mode): string
    {
        $target = $root.'/var/log/atop';
        mkdir($target, 0755, true);
        chmod($target, $mode);
        return $target;
    }

    public function testStockModeLosesAllOtherAccess(): void
    {
        $root = $this->pmssMakeTempDir('pmss-atop-stock-');
        $target = $this->makeDir($root, 0755);
        $file = $this->pmssWriteFile($target.'/atop_20261113', 'fixture');
        chmod($file, 0644);

        $this->assertTrue(\pmssProcessArchiveDirHarden($root));
        clearstatcache(true, $target);
        $this->assertSame(0750, fileperms($target) & 07777);
        $this->assertSame('fixture', file_get_contents($file));
    }

    public function testOnlyOtherBitsChange(): void
    {
        $root = $this->pmssMakeTempDir('pmss-atop-bits-');
        $target = $this->makeDir($root, 0777);

        $this->assertTrue(\pmssProcessArchiveDirHarden($root));
        clearstatcache(true, $target);
        $this->assertSame(0770, fileperms($target) & 07777);
    }

    public function testAlreadyClosedModeIsUnchanged(): void
    {
        $root = $this->pmssMakeTempDir('pmss-atop-closed-');
        $target = $this->makeDir($root, 0700);

        $this->assertTrue(\pmssProcessArchiveDirHarden($root));
        $this->assertTrue(\pmssProcessArchiveDirHarden($root));
        clearstatcache(true, $target);
        $this->assertSame(0700, fileperms($target) & 07777);
    }

    public function testMissingDirectoryIsSuccessful(): void
    {
        $root = $this->pmssMakeTempDir('pmss-atop-missing-');
        $this->assertTrue(\pmssProcessArchiveDirHarden($root));
        $this->assertFalse(file_exists($root.'/var/log/atop'));
    }

    public function testDryRunLeavesModeAlone(): void
    {
        $root = $this->pmssMakeTempDir('pmss-atop-dry-');
        $target = $this->makeDir($root, 0755);
        putenv('PMSS_DRY_RUN=1');
        try {
            $this->assertTrue(\pmssProcessArchiveDirHarden($root));
        } finally {
            putenv('PMSS_DRY_RUN');
        }
        clearstatcache(true, $target);
        $this->assertSame(0755, fileperms($target) & 07777);
    }

    public function testSymlinkIsRejectedWithoutChangingItsTarget(): void
    {
        $root = $this->pmssMakeTempDir('pmss-atop-link-');
        $outside = $root.'/outside';
        mkdir($outside, 0755);
        chmod($outside, 0755);
        mkdir($root.'/var/log', 0755, true);
        $this->pmssCreateSymlinkOrSkip($outside, $root.'/var/log/atop');

        try {
            \pmssProcessArchiveDirHarden($root);
            $this->assertTrue(false, 'expected symlink rejection');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cannot harden permissions: /var/log/atop', $exception->getMessage());
        }
        clearstatcache(true, $outside);
        $this->assertSame(0755, fileperms($outside) & 07777);
    }

    public function testRegularFileIsRejectedWithoutChangingMode(): void
    {
        $root = $this->pmssMakeTempDir('pmss-atop-file-');
        $target = $this->pmssWriteFile($root.'/var/log/atop', 'fixture');
        chmod($target, 0644);

        try {
            \pmssProcessArchiveDirHarden($root);
            $this->assertTrue(false, 'expected non-directory rejection');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cannot harden permissions: /var/log/atop', $exception->getMessage());
        }
        clearstatcache(true, $target);
        $this->assertSame(0644, fileperms($target) & 07777);
    }
}
