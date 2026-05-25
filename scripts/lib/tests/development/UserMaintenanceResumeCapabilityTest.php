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
        $dir = sys_get_temp_dir().'/pmss-urefresh-'.bin2hex(random_bytes(4));
        putenv('PMSS_USER_REFRESH_STATE_DIR='.$dir);
        try {
            $sig = pmssUserRefreshSignature('skel-sha-A');
            $this->assertTrue(!pmssUserRefreshAlreadyDone('alice', $sig), 'fresh user must not be marked done');
            pmssUserRefreshMarkDone('alice', $sig);
            $this->assertTrue(pmssUserRefreshAlreadyDone('alice', $sig), 'same-signature re-run must skip the user');
            $sig2 = pmssUserRefreshSignature('skel-sha-B');
            $this->assertTrue(!pmssUserRefreshAlreadyDone('alice', $sig2), 'version/skel change must invalidate → full refresh');
        } finally {
            putenv('PMSS_USER_REFRESH_STATE_DIR');
            if (is_dir($dir)) { @array_map('unlink', glob($dir.'/*') ?: []); @rmdir($dir); }
        }
    }

    public function testLoopChecksResumeAndMarksDone(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/userMaintenance.php');
        $this->assertTrue(
            strpos($src, 'pmssUserRefreshAlreadyDone($userTrim, $refreshSignature)') !== false,
            'the per-user loop must check the resume marker before the expensive refresh'
        );
        $this->assertTrue(
            strpos($src, 'pmssUserRefreshMarkDone($userTrim, $refreshSignature)') !== false,
            'the loop must mark a user done after a successful refresh'
        );
        // The skip must count as processed (so a fully-resumed run reports complete).
        $this->assertTrue(
            preg_match('/pmssUserRefreshAlreadyDone\([^)]*\)\)\s*\{\s*\$processedUsers\+\+;/s', $src) === 1,
            'a resumed (skipped) user must count as processed, not skipped'
        );
    }
}
