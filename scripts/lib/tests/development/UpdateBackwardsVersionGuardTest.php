<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';

/**
 * Unpinned update targets must not silently move a host to an older snapshot.
 * Emergency rollback remains available by naming a pinned target explicitly.
 */
class UpdateBackwardsVersionGuardTest extends TestCase
{
    public function testUnpinnedBackwardsMoveIsRefused(): void
    {
        $decision = \pmssVersionMoveDecision('git/main@2026-07-19 12:34', 'release:2026-01-21', false);

        $this->assertFalse($decision['allowed'], 'unpinned backwards move should be refused');
        $this->assertSame('backward', $decision['ordering']);
    }

    public function testFallbackInstalledMarkerAllowsUnpinnedBackwardOrderedFetch(): void
    {
        $metadata = $this->pmssWriteTempFile('version-meta', json_encode([
            'recorded_spec' => 'git/main',
        ]));
        $this->assertTrue(\pmssInstalledMarkerDateIsFallback($metadata), 'missing fetched_version marks stored date as fallback');

        $decision = \pmssVersionMoveDecision(
            'git/main@2026-09-10 03:04',
            'git/main@2026-09-09 06:33',
            false,
            \pmssInstalledMarkerDateIsFallback($metadata)
        );

        $this->assertTrue($decision['allowed'], 'fallback marker date should not brick the unpinned update path');
        $this->assertSame('indeterminate', $decision['ordering']);
        $this->assertSame('installed_fallback_marker', $decision['reason']);
    }

    public function testRealContentDateInstalledMarkerStillRefusesBackwardFetch(): void
    {
        $metadata = $this->pmssWriteTempFile('version-meta', json_encode([
            'fetched_version' => 'git/main@2026-09-10 03:04',
            'recorded_spec' => 'git/main',
        ]));
        $this->assertFalse(\pmssInstalledMarkerDateIsFallback($metadata), 'content-dated fetched_version remains authoritative');

        $decision = \pmssVersionMoveDecision(
            'git/main@2026-09-10 03:04',
            'git/main@2026-09-09 06:33',
            false,
            \pmssInstalledMarkerDateIsFallback($metadata)
        );

        $this->assertFalse($decision['allowed'], 'real content-date marker must still refuse a genuine backward move');
        $this->assertSame('backward', $decision['ordering']);
    }

    public function testPinnedBackwardsMoveIsAllowed(): void
    {
        $decision = \pmssVersionMoveDecision('git/main@2026-07-19 12:34', 'release:2026-01-21', true);

        $this->assertTrue($decision['allowed'], 'explicit pinned rollback should remain available');
        $this->assertSame('backward', $decision['ordering']);
    }

    public function testForwardsMoveIsAllowed(): void
    {
        $decision = \pmssVersionMoveDecision('release:2026-01-21', 'git/main@2026-07-19 12:34', false);

        $this->assertTrue($decision['allowed'], 'forwards move should proceed');
        $this->assertSame('forward', $decision['ordering']);
    }

    public function testIndeterminateOrderingProceeds(): void
    {
        $decision = \pmssVersionMoveDecision('git/main@not-a-date', 'release:not-a-date', false);

        $this->assertTrue($decision['allowed'], 'unparseable ordering should fail open');
        $this->assertSame('indeterminate', $decision['ordering']);
    }

    public function testSameDayOrderingDoesNotRefuse(): void
    {
        $decision = \pmssVersionMoveDecision('git/main@2026-07-19 12:34', 'git/main@2026-07-19 10:00', false);

        $this->assertTrue($decision['allowed'], 'same-day movement is not a proven backwards move');
        $this->assertSame('same', $decision['ordering']);
    }

    public function testGuardRunsAfterFetchAndBeforeStaging(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/update.php',
            [
                '$fetchedVersion = fetchSnapshot($spec, $workdir);',
                '$fetchedVersion = pmssFetchedVersionLine($spec, $fetchedVersion, $workdir);',
                'pmssGuardSnapshotVersionMove($fetchedVersion, $explicitVersionTarget);',
                'stageSnapshot($workdir, $options[\'dry_run\']);',
            ],
            'update.php should contain ordered guard step: '
        );
    }

    public function testRecordedMarkerUsesContentDateNotInstallTime(): void
    {
        // Fetched HEAD content dated 2026-09-06 15:06; install wall-clock 2026-09-07 09:08.
        // The marker MUST carry the CONTENT date, so a follow-up fetch of the same HEAD
        // orders as "same", not a phantom backwards move (a back-to-back
        // update.php git/main then --dist-upgrade on one host tripped the guard).
        $installTs = \mktime(9, 8, 0, 9, 7, 2026);
        $line = \pmssRecordedVersionLine('git/main', 'git/main@2026-09-06 15:06', $installTs);
        $this->assertSame('git/main@2026-09-06 15:06', $line, 'marker must carry the content commit date');

        $decision = \pmssVersionMoveDecision($line, 'git/main@2026-09-06 15:06', false);
        $this->assertTrue($decision['allowed'], 're-fetching the same HEAD must not be a backwards move');
        $this->assertSame('same', $decision['ordering']);
    }

    public function testRecordedMarkerFallsBackToCappedInstallTimeWhenLabelIsDateless(): void
    {
        $installTs = \mktime(9, 8, 0, 9, 7, 2026);
        foreach (['git/main', '', 'git/main@not-a-date', 'release', 'release:stable'] as $fetched) {
            $spec = strpos($fetched, 'release') === 0 ? $fetched : 'git/main';
            $line = \pmssRecordedVersionLine($spec, $fetched, $installTs);
            $this->assertSame($spec.'@2026-09-07 09:08', $line);
        }
    }

    public function testRecordedMarkerFallbackDateDoesNotExceedSystemClock(): void
    {
        $line = \pmssRecordedVersionLine('git/main', 'git/main', time() + 3600);
        $recorded = \pmssVersionOrderingDate($line);

        $this->assertTrue($recorded <= date('Y-m-d'), 'fallback date must not be future-relative to the system clock');
    }
}
