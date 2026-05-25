<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Regression coverage for GH#591 + GH#592.
 *
 * Root cause of the reported processed_users_mismatch is per-user maintenance
 * failures (e.g. userPermissions timeout, GH#302) — NOT the missing
 * /etc/seedbox/users/ directory, which PMSS source never reads.
 *
 * GH#592 supersedes the GH#591-era "keep must_succeed" call. GH#302's own
 * "Fix Required" explicitly endorses point 2 "Exit non-zero OR LOG A PROMINENT
 * WARNING when N<M" and point 3 "do not let one large user block the remaining
 * queue" — for its target environment (I/O-saturated dist-upgrade hosts), the
 * hard-fail blocked the whole system update and left the host stuck on old PMSS,
 * which is the worse customer outcome. The mismatch is therefore SOFT_FAIL +
 * a durable incomplete-tail marker (visibility), and the #302 service-restart
 * concern is independently covered by the per-minute watchdog crons. This test
 * pins the corrected classification + skip-reason surfacing.
 */
class UserMaintenanceSkipReasonReportingTest extends TestCase
{
    /**
     * pmssUpdateAllUsers must record a reason at every skip path and return the
     * compact reason list in its summary (and JSON event) so callers can report it.
     */
    public function testUserMaintenanceCapturesAndReturnsSkipReasons(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/userMaintenance.php', [
            '$skipReasons = [];',
            "\$skipReasons[] = '(empty): empty username entry';",
            "\$skipReasons[] = \$userTrim.': invalid username';",
            "\$skipReasons[] = \$userTrim.': '.\$reason;",
            "'skip_reasons' => \$skipReasons,",
        ]);
    }

    /**
     * update-step2 must name skipped users in the partial-completion failure
     * reason AND classify it SOFT_FAIL (GH#592 — #302's "prominent warning"
     * option, not the queue-blocking hard-fail) AND record a durable marker.
     */
    public function testUpdateStep2SurfacesSkipReasonsWithSoftFailAndMarker(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/update-step2.php', [
            'processed_users_mismatch',
            'PMSS_UPDATE_STEP_CLASS_SOFT_FAIL',
            '$processedUsers < $totalUsers',
            "\$userMaintenanceSummary['skip_reasons']",
            "' skipped=['.implode('; ', array_slice(\$skipReasons, 0, 10)).']'",
            'pmssUpdateRecordIncompleteUserMaintenance(',
        ]);
    }

    /**
     * Anti-regression guard (GH#592): the mismatch must NOT be re-promoted to
     * MUST_SUCCEED. Doing so re-blocks the whole system update on I/O-saturated
     * hosts — the exact #302 target case — leaving them stuck on old PMSS. The
     * #302 service-restart concern is covered by the watchdog crons; the
     * incomplete tail is covered by the durable marker. Re-promotion would
     * resurrect the queue-blocking behaviour #302 point 3 forbids.
     */
    public function testMismatchClassificationIsSoftFailNotMustSucceed(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $start = strpos($src, 'processed_users_mismatch');
        $this->assertTrue($start !== false, 'processed_users_mismatch block missing from update-step2');

        // Inspect the classified-failure call that follows the mismatch reason
        // assembly; it must be soft_fail (GH#592), never must_succeed. Window is
        // wide enough to clear the long GH#592 rationale comment before the call.
        $window = substr($src, $start, 1800);
        $this->assertStringContainsString(
            'PMSS_UPDATE_STEP_CLASS_SOFT_FAIL',
            $window,
            'mismatch handler must be SOFT_FAIL (GH#592 implements #302 prominent-warning option)'
        );
        $this->assertStringNotContainsStringInWindow($window, 'PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED');
    }

    private function assertStringNotContainsStringInWindow(string $haystack, string $needle): void
    {
        $this->assertTrue(
            strpos($haystack, $needle) === false,
            'mismatch handler must not use '.$needle.' (GH#592: re-blocks I/O-saturated hosts)'
        );
    }
}
