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
        $installedVersion = 'git/main@2026-09-10 03:04';
        $this->assertTrue(
            \pmssMetadataProvesInstalledFallbackDate($installedVersion, $metadata),
            'missing fetched_version marks stored date as fallback'
        );
        $installedVersion = \pmssInstalledVersionForOrdering($installedVersion, $metadata);

        $decision = \pmssVersionMoveDecision(
            $installedVersion,
            'git/main@2026-09-09 06:33',
            false
        );

        $this->assertTrue($decision['allowed'], 'fallback marker date should not brick the unpinned update path');
        $this->assertSame('indeterminate', $decision['ordering']);
    }

    public function testRealContentDateInstalledMarkerStillRefusesBackwardFetch(): void
    {
        $metadata = $this->pmssWriteTempFile('version-meta', json_encode([
            'fetched_version' => 'git/main@2026-09-10 03:04',
            'recorded_spec' => 'git/main',
        ]));
        $installedVersion = 'git/main@2026-09-10 03:04';
        $this->assertFalse(
            \pmssMetadataProvesInstalledFallbackDate($installedVersion, $metadata),
            'content-dated fetched_version remains authoritative'
        );
        $installedVersion = \pmssInstalledVersionForOrdering($installedVersion, $metadata);

        $decision = \pmssVersionMoveDecision(
            $installedVersion,
            'git/main@2026-09-09 06:33',
            false
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
        $line = \pmssRecordedVersionLine('git/main', 'git/main@2026-09-06 15:06');
        $this->assertSame('git/main@2026-09-06 15:06', $line, 'marker must carry the content commit date');

        $decision = \pmssVersionMoveDecision($line, 'git/main@2026-09-06 15:06', false);
        $this->assertTrue($decision['allowed'], 're-fetching the same HEAD must not be a backwards move');
        $this->assertSame('same', $decision['ordering']);
    }

    public function testRecordedMarkerKeepsDatelessLabelsIndeterminate(): void
    {
        foreach (['git/main', '', 'git/main@not-a-date', 'release', 'release:stable'] as $fetched) {
            $spec = strpos($fetched, 'release') === 0 ? $fetched : 'git/main';
            $line = \pmssRecordedVersionLine($spec, $fetched);
            $this->assertSame($spec, $line);

            $decision = \pmssVersionMoveDecision($line, 'git/main@2026-09-09 06:33', false);
            $this->assertTrue($decision['allowed'], 'dateless marker should keep the next guard fail-open');
            $this->assertSame('indeterminate', $decision['ordering']);
        }
    }

    public function testRecordedMarkerUsesOrderableFetchedSpecDate(): void
    {
        $this->assertSame(
            'release@2026-01-21 00:00',
            \pmssRecordedVersionLine('release', 'release:2026-01-21')
        );

        $this->assertSame(
            'git/main:2026-01-21@2026-01-21 00:00',
            \pmssRecordedVersionLine('git/main:2026-01-21', 'git/main:2026-01-21')
        );
    }
}
