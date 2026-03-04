<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ListUsersConsumersGuardTest extends TestCase
{
    /**
     * Ensure scripts that shell out to /scripts/listUsers.php also trim and
     * revalidate usernames via pmssValidateUsername before acting on them.
     */
    public function testListUsersConsumersRevalidate(): void
    {
        $targets = [
            'scripts/cron/updateQuotas.php',
            'scripts/util/setupNetwork.php',
            'scripts/util/checkUserHtpasswd.php',
            'scripts/util/userResourcesList.php',
            'scripts/util/userConfigLighttpd.php',
            'scripts/userTorrents.php',
            'scripts/cron/userTrackerCleaner.php',
            'scripts/cron/trafficIngressLog.php',
        ];

        foreach ($targets as $file) {
            $src = (string) file_get_contents(__DIR__.'/../../../../'.$file);
            $this->assertStringContainsString('listUsers.php', $src, $file.' must call listUsers.php');
            $this->assertStringContainsString('pmssValidateUsername', $src, $file.' must revalidate usernames from listUsers');
        }
    }
}
