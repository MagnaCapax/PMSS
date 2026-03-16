<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ListUsersGarbageOutputTest extends TestCase
{
    /**
     * Simulate garbage output from listUsers.php and ensure consumers are
     * resilient in their source code: they must trim entries, skip empties,
     * and revalidate usernames via pmssValidateUsername().
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

        // We do not execute scripts; instead we assert that key consumers
        // contain the guards we expect (trim + pmssValidateUsername calls),
        // which would render the above garbage safe if emitted by listUsers.
        $targets = [
            'scripts/cron/updateQuotas.php',
            'scripts/util/setupNetwork.php',
            'scripts/util/checkUserHtpasswd.php',
            'scripts/util/userResourcesList.php',
            'scripts/userTorrents.php',
            'scripts/cron/userTrackerCleaner.php',
            'scripts/cron/trafficIngressLog.php',
        ];

        foreach ($targets as $file) {
            $src = (string) file_get_contents(__DIR__.'/../../../../'.$file);
            $this->assertStringContainsString('listUsers.php', $src, $file.' must call listUsers.php');
            $this->assertTrue(
                strpos($src, 'trim($') !== false || strpos($src, "array_map('trim'") !== false,
                $file.' should trim usernames from listUsers'
            );
            $this->assertStringContainsString('pmssValidateUsername', $src, $file.' must revalidate usernames from listUsers');
        }

        // The main guard against garbage lines is that only names accepted by
        // pmssValidateUsername() (^[a-z][a-z0-9]{0,7}$) are used. Check
        // that our garbage examples would all be rejected by the validator.
        require_once __DIR__.'/../../userLifecycle.php';
        foreach ($garbageLines as $line) {
            $valid = \pmssValidateUsername(trim($line));
            $this->assertTrue(!$valid, 'Expected validator to reject garbage listUsers line: '.$line);
        }
    }
}
