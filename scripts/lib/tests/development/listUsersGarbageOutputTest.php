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

        // We do not execute scripts here; instead we assert that helper-based
        // consumers centralize listUsers sanitization via pmssListManagedUsers.
        $helperTargets = [
            'scripts/util/setupNetwork.php',
            'scripts/util/checkUserHtpasswd.php',
            'scripts/util/userResourcesList.php',
            'scripts/util/userConfigLighttpd.php',
        ];

        foreach ($helperTargets as $file) {
            $src = (string) file_get_contents(__DIR__.'/../../../../'.$file);
            $this->assertStringContainsString("pmssListManagedUsers('/scripts/listUsers.php')", $src, $file.' must use pmssListManagedUsers()');
        }

        // Direct consumers that still shell out to listUsers.php must keep explicit validation.
        $directTargets = [
            'scripts/cron/updateQuotas.php',
            'scripts/userTorrents.php',
            'scripts/cron/userTrackerCleaner.php',
            'scripts/cron/trafficIngressLog.php',
        ];

        foreach ($directTargets as $file) {
            $src = (string) file_get_contents(__DIR__.'/../../../../'.$file);
            $this->assertStringContainsString('listUsers.php', $src, $file.' must call listUsers.php');
            $this->assertStringContainsString('pmssValidateUsername', $src, $file.' must revalidate usernames from listUsers');
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
