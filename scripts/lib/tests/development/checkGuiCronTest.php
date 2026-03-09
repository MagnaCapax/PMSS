<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CheckGuiCronTest extends TestCase
{
    public function testRootCronSchedulesCheckGui(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $cron = (string) file_get_contents($repoRoot.'/etc/seedbox/config/root.cron');
        $this->assertTrue(strpos($cron, '/scripts/cron/checkGui.php') !== false, 'root.cron should schedule checkGui.php');
    }

    public function testCheckGuiRepairsCoreUserspacePaths(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $script = (string) file_get_contents($repoRoot.'/scripts/cron/checkGui.php');

        $this->assertTrue(strpos($script, 'pmssCheckGuiEnsureUserDirectory($wwwDir') !== false, 'checkGui should repair missing www directory');
        $this->assertTrue(strpos($script, 'pmssCheckGuiEnsureUserDirectory($dataDir') !== false, 'checkGui should repair missing data directory');
        $this->assertTrue(strpos($script, 'pmssCheckGuiRestoreUserIndex') !== false, 'checkGui should restore missing GUI index.php');
    }
}

