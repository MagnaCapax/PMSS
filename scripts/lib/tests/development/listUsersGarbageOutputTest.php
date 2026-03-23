<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ListUsersGarbageOutputTest extends TestCase
{
    /**
     * Simulate garbage output from listUsers.php and ensure helper-based
     * consumers delegate sanitization to pmssListManagedUsers(), while direct
     * callers still keep their own validation guards.
     *
     * This test is static/source-based rather than executing the scripts
     * because reproducing cron + filesystem state in CI is brittle.
     */
    public function testConsumersDefendAgainstGarbageLines(): void
    {
        $garbageLines = [
            '', // empty
            ' ', // whitespace
            'Fatal error: Uncaught Error: ...',
            '#0 /scripts/lib/users.php(10): require_once()',
            'Stack trace:',
            'Disk quotas for user root (uid 0): none',
            'rm: cannot remove \'/home//.quota\': Read-only file system',
            'sh: 1: Syntax error: "(" unexpected',
            'Warning: require_once(/scripts/lib/user/userRepository.php): Failed to open stream:',
            'user; rm -rf /home/*; #',
        ];

        foreach ([
            "pmssListManagedUsers('/scripts/listUsers.php')" => [
                'scripts/cron/trafficIngressLog.php',
                'scripts/util/checkRutorrentPlugins.php',
                'scripts/util/makeMonitoringRules.php',
                'scripts/util/setupNetwork.php',
                'scripts/lib/user/resourcesList.php',
            ],
            'pmssManagedUsersSelectFromCommand(' => [
                'scripts/cron/checkLighttpdInstances.php',
                'scripts/util/checkUserHtpasswd.php',
                'scripts/util/userConfigLighttpd.php',
                'scripts/lib/nginxConfig/main.php',
            ],
            'pmssListManagedUsersResult(' => ['scripts/lib/resources/show.php', 'scripts/showTraffic.php', 'scripts/userTorrents.php'],
        ] as $needle => $files) {
            foreach ($files as $file) {
                $this->pmssAssertRepoFileContainsAllStrings($file, [$needle], $file.' must use shared listUsers parsing');
            }
        }

        // Direct consumers that still shell out to listUsers.php must keep explicit validation.
        $directTargets = [
            'scripts/cron/updateQuotas.php',
            'scripts/cron/userTrackerCleaner.php',
        ];

        foreach ($directTargets as $file) {
            $this->pmssAssertRepoFileContainsAllStrings(
                $file,
                ['listUsers.php', 'pmssValidateUsername'],
                $file.' must keep direct listUsers validation'
            );
        }

        // The main guard against garbage lines is that only names accepted by
        // pmssValidateUsername() (^[a-z][a-z0-9]{0,7}$) are used. Check
        // that our garbage examples would all be rejected by the validator.
        foreach ($garbageLines as $line) {
            $valid = \pmssValidateUsername(trim($line));
            $this->assertTrue(!$valid, 'Expected validator to reject garbage listUsers line: '.$line);
        }
    }
}
