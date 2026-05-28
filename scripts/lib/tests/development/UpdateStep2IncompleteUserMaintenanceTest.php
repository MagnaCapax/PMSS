<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../update/runtime/stepPolicy.php';

/**
 * GH#592: a per-user maintenance miss (most commonly userPermissions timeout on
 * an I/O-saturated host) must NOT hard-fail the whole system update. It is
 * SOFT_FAIL + a durable incomplete-tail marker for visibility. The GH#302
 * concern (skipped users get no service restart) is independently covered by
 * the per-minute watchdog crons.
 */
class UpdateStep2IncompleteUserMaintenanceTest extends TestCase
{
    public function testMismatchIsSoftFailNotMustSucceed(): void
    {
        $data = $this->pmssReadRepoFile('scripts/lib/update/runtime/stepPolicy.php');
        // The processed_users_mismatch call must classify SOFT_FAIL.
        $this->assertTrue(
            strpos($data, "PMSS_UPDATE_STEP_CLASS_SOFT_FAIL,\n        1,\n        pmssUpdateStep2UserMaintenanceMismatchReason") !== false
            || preg_match('/pmssUpdateStep2HandleClassifiedFailure\(\s*\'Updating all user environments\',\s*PMSS_UPDATE_STEP_CLASS_SOFT_FAIL/s', $data) === 1,
            'processed_users_mismatch must be SOFT_FAIL (GH#592), not MUST_SUCCEED'
        );
        // Guard against naive re-promotion: the mismatch block must NOT use MUST_SUCCEED.
        $this->assertTrue(
            preg_match('/\'Updating all user environments\',\s*PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED/s', $data) !== 1,
            'the user-mismatch step must not be re-promoted to MUST_SUCCEED without revisiting GH#592/#302'
        );
        $this->assertSame(
            'processed_users_mismatch:3_of_15 skipped=[u1; u2]',
            pmssUpdateStep2UserMaintenanceMismatchReason(3, 15, ['u1', 'u2'])
        );
    }

    public function testIncompleteTailMarkerWrittenThenCleared(): void
    {
        $tmp = sys_get_temp_dir().'/pmss-test-incomplete-'.bin2hex(random_bytes(4)).'.json';
        putenv('PMSS_INCOMPLETE_USER_MAINTENANCE_PATH='.$tmp);
        try {
            pmssUpdateRecordIncompleteUserMaintenance(21, 22, ['u: RuntimeException: userPermissions timeout after 900s']);
            $this->assertTrue(is_file($tmp), 'incomplete-tail marker must be written on mismatch');
            $decoded = json_decode((string) file_get_contents($tmp), true);
            $this->assertTrue(is_array($decoded) && ($decoded['processed'] ?? null) === 21 && ($decoded['total'] ?? null) === 22,
                'marker must record processed/total counts');
            $this->assertTrue(isset($decoded['skipped'][0]) && strpos($decoded['skipped'][0], 'timeout') !== false,
                'marker must record skip reasons for operator visibility');
            pmssUpdateClearIncompleteUserMaintenance();
            $this->assertTrue(!is_file($tmp), 'marker must be cleared once all users process');
        } finally {
            putenv('PMSS_INCOMPLETE_USER_MAINTENANCE_PATH');
            if (is_file($tmp)) { @unlink($tmp); }
        }
    }

    public function testFullCompletionClearsStaleMarker(): void
    {
        // The policy helper clears the marker on a clean run.
        $data = $this->pmssReadRepoFile('scripts/lib/update/runtime/stepPolicy.php');
        $this->assertTrue(
            strpos($data, 'pmssUpdateClearIncompleteUserMaintenance()') !== false,
            'a clean run (all users processed) must clear a stale incomplete-tail marker'
        );
    }
}
