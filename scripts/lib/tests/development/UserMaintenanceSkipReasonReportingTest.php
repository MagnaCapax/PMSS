<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Regression coverage for GH#591.
 *
 * Root cause of the reported processed_users_mismatch is per-user maintenance
 * failures (e.g. userPermissions timeout, GH#302) — NOT the missing
 * /etc/seedbox/users/ directory, which PMSS source never reads. The fix keeps
 * the must_succeed guard (silently-skipped users break for customers, GH#302)
 * and instead surfaces WHY users were skipped so operators act on the real
 * cause rather than chasing correlated host state.
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
     * reason AND must keep the must_succeed classification (no #302 regression).
     */
    public function testUpdateStep2SurfacesSkipReasonsWithoutWeakeningGuard(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/update-step2.php', [
            'processed_users_mismatch',
            'PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED',
            '$processedUsers < $totalUsers',
            "\$userMaintenanceSummary['skip_reasons']",
            "' skipped=['.implode('; ', array_slice(\$skipReasons, 0, 10)).']'",
        ]);
    }

    /**
     * The mismatch must NOT be downgraded to a soft/warn-only classification.
     * GH#591 suggested loosening must_succeed; doing so would re-introduce the
     * GH#302 bug where silently-skipped users get no service restart.
     */
    public function testMismatchClassificationIsNotDowngradedToSoftFail(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $start = strpos($src, 'processed_users_mismatch');
        $this->assertTrue($start !== false, 'processed_users_mismatch block missing from update-step2');

        // Inspect the classified-failure call that immediately follows the
        // mismatch reason assembly; it must still be must_succeed.
        $window = substr($src, $start, 600);
        $this->assertStringContainsString(
            'PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED',
            $window,
            'mismatch handler must keep must_succeed classification (GH#302 guard)'
        );
        $this->assertStringNotContainsStringInWindow($window, 'PMSS_UPDATE_STEP_CLASS_SOFT_FAIL');
        $this->assertStringNotContainsStringInWindow($window, 'PMSS_UPDATE_STEP_CLASS_WARN');
    }

    private function assertStringNotContainsStringInWindow(string $haystack, string $needle): void
    {
        $this->assertTrue(
            strpos($haystack, $needle) === false,
            'mismatch handler must not use '.$needle.' (would weaken the GH#302 guard)'
        );
    }
}
