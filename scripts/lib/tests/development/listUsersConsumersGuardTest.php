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
            "userFilesystem::listManagedUsersWithAdditionalUsers(['www-data'])" => [
                'scripts/cron/resourceLog.php',
                'scripts/cron/resourceSnapshot.php',
                'scripts/cron/trafficLog.php',
                'scripts/cron/trafficIngressLog.php',
                'scripts/util/makeMonitoringRules.php',
            ],
            "pmssListManagedUsers('/scripts/listUsers.php')" => [
                'scripts/cron/trafficLimits.php',
                'scripts/cron/updateQuotas.php',
                'scripts/util/checkRutorrentPlugins.php',
                'scripts/util/setupNetwork.php',
                'scripts/lib/user/resourcesList.php',
            ],
            'pmssManagedUsersSelectFromCommand(' => [
                'scripts/cron/checkLighttpdInstances.php',
                'scripts/util/checkUserHtpasswd.php',
                'scripts/util/userConfigLighttpd.php',
                'scripts/lib/nginxConfig/main.php',
            ],
            'pmssListManagedUsersResult(' => [
                'scripts/cron/checkRtorrent.php',
                'scripts/cron/userTrackerCleaner.php',
                'scripts/lib/resources/show.php',
                'scripts/showTraffic.php',
                'scripts/userTorrents.php',
            ],
        ] as $needle => $files) {
            foreach ($files as $file) {
                $this->pmssAssertRepoFileContainsAllStrings($file, [$needle], $file.' must use shared listUsers parsing');
            }
            if ($needle === 'pmssListManagedUsersResult(') {
                continue;
            }
            foreach ($files as $file) {
                $this->pmssAssertRepoFileNotContainsStrings(
                    $file,
                    [
                        "array_map('trim', pmssListManagedUsers",
                        "array_filter(pmssListManagedUsers('/scripts/listUsers.php'), 'pmssValidateUsername')",
                        'pmssManagedUsersSelectFromList(pmssListManagedUsers(',
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
    public function testMigratedConsumersDropLegacyListUsersShelling(): void
    {
        $targets = [
            'scripts/cron/trafficLog.php',
            'scripts/cron/trafficLimits.php',
            'scripts/cron/updateQuotas.php',
            'scripts/cron/checkRtorrent.php',
            'scripts/cron/userTrackerCleaner.php',
        ];

        foreach ($targets as $file) {
            $this->pmssAssertRepoFileNotContainsStrings(
                $file,
                [
                    "shell_exec('/scripts/listUsers.php')",
                    "@exec('/scripts/listUsers.php",
                    "`/scripts/listUsers.php`",
                ],
                $file.' must use shared listUsers helpers instead of inline shelling'
            );
        }
    }
}
