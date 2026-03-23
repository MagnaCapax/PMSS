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
        foreach ([
            "pmssListManagedUsers('/scripts/listUsers.php')" => [
                'scripts/cron/trafficIngressLog.php',
                'scripts/util/checkRutorrentPlugins.php',
                'scripts/util/makeMonitoringRules.php',
                'scripts/util/setupNetwork.php',
                'scripts/util/checkUserHtpasswd.php',
                'scripts/util/userResourcesList.php',
                'scripts/util/userConfigLighttpd.php',
            ],
            'pmssListManagedUsersResult(' => ['scripts/lib/resources/show.php', 'scripts/showTraffic.php', 'scripts/userTorrents.php'],
        ] as $needle => $files) {
            foreach ($files as $file) {
                $this->pmssAssertRepoFileContainsAllStrings($file, [$needle], $file.' must use shared listUsers parsing');
            }
            if ($needle !== "pmssListManagedUsers('/scripts/listUsers.php')") {
                continue;
            }
            foreach ($files as $file) {
                $this->pmssAssertRepoFileNotContainsStrings(
                    $file,
                    [
                        "array_map('trim', pmssListManagedUsers",
                        "array_filter(pmssListManagedUsers('/scripts/listUsers.php'), 'pmssValidateUsername')",
                    ],
                    $file.' should keep pmssListManagedUsers() output as-is '
                );
            }
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
            'scripts/cron/userTrackerCleaner.php',
        ];

        foreach ($targets as $file) {
            $this->pmssAssertRepoFileContainsAllStrings(
                $file,
                ['listUsers.php', 'pmssValidateUsername'],
                $file.' must keep direct listUsers validation'
            );
        }
    }
}
