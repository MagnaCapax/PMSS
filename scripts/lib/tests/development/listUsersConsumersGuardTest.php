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
            $this->pmssAssertRepoFileContainsString($file, "pmssListManagedUsers('/scripts/listUsers.php')", $file.' must use pmssListManagedUsers()');
            $this->pmssAssertRepoFileNotContainsString($file, "array_map('trim', pmssListManagedUsers", $file.' should not re-trim pmssListManagedUsers() output');
            $this->pmssAssertRepoFileNotContainsString(
                $file,
                "array_filter(pmssListManagedUsers('/scripts/listUsers.php'), 'pmssValidateUsername')",
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
            $this->pmssAssertRepoFileContainsString($file, 'listUsers.php', $file.' must call listUsers.php');
            $this->pmssAssertRepoFileContainsString($file, 'pmssValidateUsername', $file.' must revalidate usernames from listUsers');
        }
    }
}
