<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ListUsersConsumersGuardTest extends TestCase
{
    /**
     * Ensure helper-based consumers rely on pmssListManagedUsers() instead of
     * re-implementing the legacy listUsers sanitization inline.
     */
    public function testHelperConsumersRelyOnSharedManagedUserParser(): void
    {
        $targets = [
            'scripts/util/setupNetwork.php',
            'scripts/util/checkUserHtpasswd.php',
            'scripts/util/userResourcesList.php',
            'scripts/util/userConfigLighttpd.php',
        ];

        foreach ($targets as $file) {
            $src = $this->pmssReadRepoFile($file);
            $this->assertStringContainsString("pmssListManagedUsers('/scripts/listUsers.php')", $src, $file.' must use pmssListManagedUsers()');
            $this->assertTrue(
                strpos($src, "array_map('trim', pmssListManagedUsers") === false,
                $file.' should not re-trim pmssListManagedUsers() output'
            );
            $this->assertTrue(
                strpos($src, "array_filter(pmssListManagedUsers('/scripts/listUsers.php'), 'pmssValidateUsername')") === false,
                $file.' should not revalidate pmssListManagedUsers() output inline'
            );
        }
    }

    /**
     * Ensure scripts that still shell out directly to listUsers.php keep their
     * own username validation guards.
     */
    public function testDirectListUsersConsumersStillRevalidate(): void
    {
        $targets = [
            'scripts/cron/updateQuotas.php',
            'scripts/userTorrents.php',
            'scripts/cron/userTrackerCleaner.php',
            'scripts/cron/trafficIngressLog.php',
        ];

        foreach ($targets as $file) {
            $src = $this->pmssReadRepoFile($file);
            $this->assertStringContainsString('listUsers.php', $src, $file.' must call listUsers.php');
            $this->assertStringContainsString('pmssValidateUsername', $src, $file.' must revalidate usernames from listUsers');
        }
    }
}
