<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Keep cron setup's systemd reload ahead of the final direct BFQ restore. */
class cgroupBfqUpdateFinalRestoreTest extends TestCase
{
    public function testFinalRestoreFollowsRootCronSetupBeforeCompletion(): void
    {
        // Inspect orchestration without running the updater or touching cgroups.
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/util/update-step2.php', [
            "runStep('Refreshing root cron configuration', '/scripts/util/setupRootCron.php');",
            '$pmssRootCronRestored = true;',
            "runStep('Restoring direct BFQ weights after root cron restore', '/scripts/cron/cgroupBfqWeightApply.php');",
            "pmssWriteManagedPathFile('/var/run/pmss/updated'",
        ]);
    }
}
