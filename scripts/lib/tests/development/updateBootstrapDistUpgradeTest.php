<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateBootstrapDistUpgradeTest extends TestCase
{
    public function testBootstrapRestoresCronAfterDistUpgrade(): void
    {
        $data = $this->pmssReadRepoFile('scripts/update.php');

        $this->assertTrue(
            strpos($data, "restoreRootCronBestEffort('dist-upgrade')") !== false,
            'dist-upgrade flow should restore root cron (setupRootCron.php) before exiting'
        );
    }
}
