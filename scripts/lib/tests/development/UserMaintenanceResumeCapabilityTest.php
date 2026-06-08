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

    public function testHandlerCatalogAndLegacyCpuQuotaHelperPreserveContracts(): void
    {
        $this->assertSame(['pmssUserConfigureHttp', 'pmssUserApplySkeletonFiles', 'pmssUserUpdateThemes', 'pmssUserUpgradeRutorrent', 'pmssUserMaintainRutorrentPhpCompatibility', 'pmssUserEnsurePlugins', 'pmssUserRefreshPermissions'], pmssUserEnvironmentHandlers());

        $sliceDir = $this->pmssMakeTempDir('pmss-umaint-cpu-', 0700);
        $this->pmssWriteFile($sliceDir.'/legacy.conf', "CPUQuota=85%\n");
        $this->assertTrue(pmssUserMaintenanceLegacyCpuQuotaNeedsFix($sliceDir));

        $cleanDir = $this->pmssMakeTempDir('pmss-umaint-cpu-clean-', 0700);
        $this->pmssWriteFile($cleanDir.'/clean.conf', "CPUQuota=250%\n#CPUQuota=85%\nCPUQuota=85.0%\n");
        $this->assertFalse(pmssUserMaintenanceLegacyCpuQuotaNeedsFix($cleanDir));
    }
}
