<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CheckGuiCronTest extends TestCase
{
    public function testRootCronSchedulesCheckGui(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/root.cron', '/scripts/cron/checkGui.php', 'root.cron should schedule checkGui.php');
    }

    public function testCheckGuiRepairsCoreUserspacePaths(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/checkGui.php',
            [
                'pmssCheckGuiEnsureUserDirectory($wwwDir',
                'pmssCheckGuiEnsureUserDirectory($dataDir',
                'pmssCheckGuiRestoreUserIndex',
            ],
            'checkGui should keep core userspace repair wiring: '
        );
    }
}
