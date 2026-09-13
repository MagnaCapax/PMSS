<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../update/userMaintenance.php';

/**
 * GH#302 point 5 — resume capability. On I/O-saturated hosts a run can time out
 * mid-queue; #592 made that non-blocking (soft-fail). This makes the same-version
 * re-run efficient: users already fully refreshed against the current PMSS
 * version are skipped, so the re-run converges on the timed-out tail instead of
 * re-walking every home. A version/skel change invalidates the signature →
 * full refresh (new logic must reach every user).
 */
class UserMaintenanceResumeCapabilityTest extends TestCase
{
    public function testMarkerSkipsSameSignatureAndInvalidatesOnChange(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-urefresh-', 0700);
        $this->pmssWithEnv(['PMSS_USER_REFRESH_STATE_DIR' => $dir], function (): void {
            $sig = pmssUserRefreshSignature('skel-sha-A');
            $this->assertTrue(!pmssUserRefreshAlreadyDone('alice', $sig), 'fresh user must not be marked done');
            pmssUserRefreshMarkDone('alice', $sig);
            $this->assertTrue(pmssUserRefreshAlreadyDone('alice', $sig), 'same-signature re-run must skip the user');
            $sig2 = pmssUserRefreshSignature('skel-sha-B');
            $this->assertTrue(!pmssUserRefreshAlreadyDone('alice', $sig2), 'version/skel change must invalidate → full refresh');
        });
    }

    public function testMarkerRejectsInvalidUsernameBeforeFilesystemWrite(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-urefresh-safe-', 0700);
        $escapePath = dirname($dir).'/pmss-urefresh-escape-'.bin2hex(random_bytes(4));

        $this->pmssWithEnv(['PMSS_USER_REFRESH_STATE_DIR' => $dir], function () use ($escapePath): void {
            $invalidUser = '../'.basename($escapePath);
            ob_start();
            pmssUserRefreshMarkDone($invalidUser, 'sig');
            $output = (string) ob_get_clean();

            $this->assertSame('', pmssUserRefreshMarkerPath($invalidUser));
            $this->assertFalse(pmssUserRefreshAlreadyDone($invalidUser, 'sig'));
            $this->assertFalse(file_exists($escapePath), 'invalid usernames must not escape the marker directory');
            $this->assertStringContainsString('Refusing to write unsafe user refresh marker', $output);
        });
    }

    public function testMarkerRejectsUnsafeStateDirectoryBeforeWrite(): void
    {
        $root = $this->pmssMakeTempDir('pmss-urefresh-state-', 0700);
        $unsafeDir = $root.'/state/../escape';

        $this->pmssWithEnv(['PMSS_USER_REFRESH_STATE_DIR' => $unsafeDir], function () use ($root): void {
            ob_start();
            pmssUserRefreshMarkDone('alice', 'sig');
            $output = (string) ob_get_clean();

            $this->assertSame('', pmssUserRefreshMarkerPath('alice'));
            $this->assertFalse(pmssUserRefreshAlreadyDone('alice', 'sig'));
            $this->assertFalse(is_dir($root.'/escape'), 'unsafe state dirs must not be materialized');
            $this->assertStringContainsString('Refusing to write unsafe user refresh marker', $output);
        });
    }

    public function testMarkerRejectsSymlinkLeavesWithoutChangingTheirTargets(): void
    {
        $root = $this->pmssMakeTempDir('pmss-urefresh-links-', 0700);
        $stateDir = $root.'/state';
        mkdir($stateDir, 0700);
        file_put_contents($root.'/target', "old-signature\n");
        mkdir($root.'/directory', 0700);

        foreach (['target', 'missing', 'directory'] as $target) {
            $marker = $stateDir.'/alice';
            $this->assertTrue(symlink($root.'/'.$target, $marker));
            $this->assertUnsafeMarkerRejected($stateDir);
            $this->assertTrue(is_link($marker));
            $this->assertSame($root.'/'.$target, readlink($marker));
            $this->assertSame("old-signature\n", file_get_contents($root.'/target'));
            $this->assertFalse(file_exists($root.'/missing'));
            $this->assertSame(['.', '..'], scandir($root.'/directory'));
            unlink($marker);
        }
    }

    public function testMarkerRejectsDirectoryLeafWithoutRemovingContents(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-urefresh-directory-', 0700);
        mkdir($stateDir.'/alice', 0700);
        file_put_contents($stateDir.'/alice/sentinel', 'keep');

        $this->assertUnsafeMarkerRejected($stateDir);
        $this->assertSame('keep', file_get_contents($stateDir.'/alice/sentinel'));
    }

    public function testMarkerRejectsFifoLeafBeforeOpeningIt(): void
    {
        if (!function_exists('posix_mkfifo')) {
            throw new SkipTest('posix_mkfifo unavailable');
        }
        $stateDir = $this->pmssMakeTempDir('pmss-urefresh-fifo-', 0700);
        $this->assertTrue(posix_mkfifo($stateDir.'/alice', 0600));

        // The path assertion runs first so a regression fails before a FIFO open can block.
        $this->assertUnsafeMarkerRejected($stateDir);
        $this->assertSame('fifo', filetype($stateDir.'/alice'));
    }

    public function testMarkerCreatesMissingStateDirectoryAndReplacesRegularFileSilently(): void
    {
        $root = $this->pmssMakeTempDir('pmss-urefresh-create-', 0700);
        $stateDir = $root.'/state';
        $this->pmssWithEnv(['PMSS_USER_REFRESH_STATE_DIR' => $stateDir], function () use ($stateDir): void {
            ob_start();
            try {
                pmssUserRefreshMarkDone('alice', 'old-signature');
                pmssUserRefreshMarkDone('alice', 'new-signature');
            } finally {
                $output = (string) ob_get_clean();
            }

            $this->assertSame('', $output);
            $this->assertSame($stateDir.'/alice', pmssUserRefreshMarkerPath('alice'));
            $this->assertSame("new-signature\n", file_get_contents($stateDir.'/alice'));
            $this->assertTrue(pmssUserRefreshAlreadyDone('alice', 'new-signature'));
            $this->assertFalse(pmssUserRefreshAlreadyDone('alice', 'old-signature'));
        });
    }

    private function assertUnsafeMarkerRejected(string $stateDir): void
    {
        $this->pmssWithEnv([
            'PMSS_USER_REFRESH_STATE_DIR' => $stateDir,
            'PMSS_LOG_FILE' => $stateDir.'/test.log',
        ], function (): void {
            $this->assertSame('', pmssUserRefreshMarkerPath('alice'));
            $this->assertFalse(pmssUserRefreshAlreadyDone('alice', 'old-signature'));
            ob_start();
            try {
                pmssUserRefreshMarkDone('alice', 'new-signature');
            } finally {
                $output = (string) ob_get_clean();
            }
            $this->assertStringContainsString('Refusing to write unsafe user refresh marker', $output);
        });
    }

    public function testHandlerCatalogAndLegacyCpuQuotaHelperPreserveContracts(): void
    {
        $this->assertSame(['pmssUserConfigureHttp', 'pmssUserApplySkeletonFiles', 'pmssUserUpdateThemes', 'pmssUserUpgradeRutorrent', 'pmssUserMaintainRutorrentPhpCompatibility', 'pmssUserEnsurePlugins', 'pmssUserRefreshPermissions'], pmssUserEnvironmentHandlers());

        foreach (array(
            'legacy quota' => array('pmss-umaint-cpu-', 'legacy.conf', "CPUQuota=85%\n", true),
            'clean quota' => array('pmss-umaint-cpu-clean-', 'clean.conf', "CPUQuota=250%\n#CPUQuota=85%\nCPUQuota=85.0%\n", false),
        ) as $label => $case) {
            $sliceDir = $this->pmssMakeTempDir($case[0], 0700);
            $this->pmssWriteFile($sliceDir.'/'.$case[1], $case[2]);
            $this->assertSame($case[3], pmssUserMaintenanceLegacyCpuQuotaNeedsFix($sliceDir), $label);
        }
    }
}
