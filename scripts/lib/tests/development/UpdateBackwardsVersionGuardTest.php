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
}
