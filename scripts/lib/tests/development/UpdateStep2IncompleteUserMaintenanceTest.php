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
    public function testSummaryPreservesMarkerAndClassifiedFailurePayloads(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-step2-state-');
        $marker = $dir.'/incomplete.json';
        $previousLogPath = $GLOBALS['PMSS_JSON_LOG_PATH'] ?? null;
        $cases = [
            [['processed' => 3, 'total' => 15, 'skip_reasons' => ['u1', 'u2']], 'processed_users_mismatch:3_of_15 skipped=[u1; u2]'],
            [['total' => 2], 'processed_users_mismatch:0_of_2'],
            [['processed' => '1', 'total' => '2', 'skip_reasons' => 'invalid'], 'processed_users_mismatch:1_of_2'],
            [['processed' => -1, 'total' => 0], 'processed_users_mismatch:-1_of_0'],
            [['processed' => 0, 'total' => 12, 'skip_reasons' => range(1, 12)], 'processed_users_mismatch:0_of_12 skipped=[1; 2; 3; 4; 5; 6; 7; 8; 9; 10]'],
        ];
        try {
            $this->pmssWithEnv(['PMSS_INCOMPLETE_USER_MAINTENANCE_PATH' => $marker], function () use ($dir, $marker, $cases): void {
                foreach ($cases as $index => [$summary, $reason]) {
                    $GLOBALS['PMSS_JSON_LOG_PATH'] = $dir.'/events-'.$index.'.jsonl';
                    pmssUpdateStep2HandleUserMaintenanceSummary($summary);
                    $stored = $this->pmssReadJsonArrayFile($marker);
                    unset($stored['ts']);
                    $this->assertSame([
                        'processed' => (int) ($summary['processed'] ?? 0), 'total' => (int) $summary['total'],
                        'skipped' => is_array($summary['skip_reasons'] ?? null) ? array_values($summary['skip_reasons']) : [],
                    ], $stored);
                    $events = array_map(static function ($line) { return json_decode($line, true); }, file($GLOBALS['PMSS_JSON_LOG_PATH']));
                    $failures = array_values(array_filter($events, static function ($event): bool { return $event['event'] === 'step_failed'; }));
                    $this->assertSame(1, count($failures));
                    unset($failures[0]['ts'], $failures[0]['pmss_correlation_id']);
                    $this->assertSame([
                        'event' => 'step_failed', 'severity' => 'warn', 'classification' => 'soft_fail',
                        'step' => 'Updating all user environments', 'rc' => 1, 'reason' => $reason,
                    ], $failures[0]);
                }
                // Invalid summaries leave existing evidence; complete/overcomplete runs clear it.
                foreach ([null, false, 'invalid', 0] as $invalid) pmssUpdateStep2HandleUserMaintenanceSummary($invalid);
                $this->assertTrue(is_file($marker));
                foreach ([[], ['processed' => 2, 'total' => 2], ['processed' => 3, 'total' => 2]] as $complete) {
                    file_put_contents($marker, '{}');
                    pmssUpdateStep2HandleUserMaintenanceSummary($complete);
                    $this->assertFalse(is_file($marker));
                }
            });
        } finally {
            $GLOBALS['PMSS_JSON_LOG_PATH'] = $previousLogPath;
        }
    }

    public function testMismatchIsSoftFailNotMustSucceed(): void
    {
        $data = $this->pmssReadRepoFile('scripts/lib/update/runtime/userMaintenanceState.php');
        // The processed_users_mismatch call must classify SOFT_FAIL.
        $this->assertTrue(
            preg_match('/pmssUpdateStep2HandleClassifiedFailure\(\s*\'Updating all user environments\',\s*PMSS_UPDATE_STEP_CLASS_SOFT_FAIL/s', $data) === 1,
            'processed_users_mismatch must be SOFT_FAIL (GH#592), not MUST_SUCCEED'
        );
        // Guard against naive re-promotion: the mismatch block must NOT use MUST_SUCCEED.
        $this->assertTrue(
            preg_match('/\'Updating all user environments\',\s*PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED/s', $data) !== 1,
            'the user-mismatch step must not be re-promoted to MUST_SUCCEED without revisiting GH#592/#302'
        );
    }

    public function testIncompleteTailMarkerWrittenThenCleared(): void
    {
        $tmp = sys_get_temp_dir().'/pmss-test-incomplete-'.bin2hex(random_bytes(4)).'.json';
        putenv('PMSS_INCOMPLETE_USER_MAINTENANCE_PATH='.$tmp);
        try {
            pmssUpdateRecordIncompleteUserMaintenance(21, 22, ['u: RuntimeException: userPermissions timeout after 900s']);
            $this->assertTrue(is_file($tmp), 'incomplete-tail marker must be written on mismatch');
            $decoded = $this->pmssReadJsonArrayFile($tmp);
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

    public function testIncompleteTailMarkerRejectsRelativeOverridePath(): void
    {
        $relative = 'pmss-test-incomplete-relative.json';
        putenv('PMSS_INCOMPLETE_USER_MAINTENANCE_PATH='.$relative);
        try {
            pmssUpdateRecordIncompleteUserMaintenance(1, 2, ['alice: skipped']);
            $this->assertFalse(is_file($relative), 'relative marker path must not be written');
            $this->assertFalse(pmssUpdateIncompleteUserMaintenancePathIsSafe($relative));
        } finally {
            putenv('PMSS_INCOMPLETE_USER_MAINTENANCE_PATH');
            if (is_file($relative)) { @unlink($relative); }
        }
    }

    public function testIncompleteTailMarkerRejectsSymlinkedParentOnClear(): void
    {
        $root = sys_get_temp_dir().'/pmss-test-incomplete-root-'.bin2hex(random_bytes(4));
        $targetDir = $root.'/target';
        $linkDir = $root.'/link';
        @mkdir($targetDir, 0755, true);
        if (!@symlink($targetDir, $linkDir)) {
            throw new SkipTest('symlink unavailable');
        }

        $marker = $linkDir.'/marker.json';
        $realMarker = $targetDir.'/marker.json';
        file_put_contents($realMarker, "{}\n");
        putenv('PMSS_INCOMPLETE_USER_MAINTENANCE_PATH='.$marker);
        try {
            pmssUpdateClearIncompleteUserMaintenance();
            $this->assertTrue(is_file($realMarker), 'marker behind symlinked parent must not be removed');
            $this->assertFalse(pmssUpdateIncompleteUserMaintenancePathIsSafe($marker));
        } finally {
            putenv('PMSS_INCOMPLETE_USER_MAINTENANCE_PATH');
            if (is_file($realMarker)) { @unlink($realMarker); }
            if (is_link($linkDir)) { @unlink($linkDir); }
            if (is_dir($targetDir)) { @rmdir($targetDir); }
            if (is_dir($root)) { @rmdir($root); }
        }
    }

    public function testFullCompletionClearsStaleMarker(): void
    {
        // The policy helper clears the marker on a clean run.
        $data = $this->pmssReadRepoFile('scripts/lib/update/runtime/userMaintenanceState.php');
        $this->assertTrue(
            strpos($data, 'pmssUpdateClearIncompleteUserMaintenance()') !== false,
            'a clean run (all users processed) must clear a stale incomplete-tail marker'
        );
    }
}
