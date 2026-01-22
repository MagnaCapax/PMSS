<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateBootstrapDistUpgradeTest extends TestCase
{
    public function testBootstrapRestoresCronAfterDistUpgrade(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/update.php';
        $this->assertTrue(file_exists($path), 'scripts/update.php missing');
        $data = (string) file_get_contents($path);

        $this->assertTrue(
            strpos($data, "restoreRootCronBestEffort('dist-upgrade')") !== false,
            'dist-upgrade flow should restore root cron (setupRootCron.php) before exiting'
        );
    }
}

