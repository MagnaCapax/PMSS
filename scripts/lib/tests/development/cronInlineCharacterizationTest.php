<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CronInlineCharacterizationTest extends TestCase
{
    public function testBootTuningKeepsFileWritesInline(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/systemPrep.php');
        $wrapperNeedle = '$write'.'Target = static function';

        $this->assertTrue(
            strpos($src, $wrapperNeedle) === false,
            'pmssEnsureBootTuning() should keep its two file writes inline rather than via a local wrapper'
        );
        $this->assertStringContainsString('[$scriptTarget, $scriptRaw, 0755, \'Boot tuning script\']', $src);
        $this->assertStringContainsString('[$serviceTarget, $serviceRaw, 0644, \'Boot tuning service\']', $src);
        $this->assertStringContainsString('$log(\'Installed \'.$label.\' at \'.$path);', $src);
        $this->assertStringContainsString("@rename(\$tmp, \$path)", $src);
    }

    public function testServiceWatchdogsUseSharedHelpersAndKeepCommands(): void
    {
        foreach ([
            [
                'scripts/cron/checkQbittorrentInstances.php',
                ['pmssUserWatchdogProcessRunning($thisUser, \'qbittorrent-nox\')', 'pmssUserWatchdogStartCommand($thisUser, \'qBittorrent\'', 'nohup qbittorrent-nox -d >> /dev/null 2>&1 &', "'qbittorrent-nox start requested'"],
            ],
            [
                'scripts/cron/checkRcloneInstances.php',
                ['pmssUserWatchdogProcessRunning($thisUser, \'rclone\')', 'pmssUserWatchdogStartCommand($thisUser, \'rclone\'', '--rc-web-gui --rc-addr 127.0.0.1:{$port}', "'rclone start requested'"],
            ],
            [
                'scripts/cron/checkDelugeInstances.php',
                ['pmssUserWatchdogProcessRunning($thisUser, \'deluged\')', 'pmssUserWatchdogTerminateProcesses($thisUser, [\'deluged\', \'deluge-web\'], 9);', 'pmssUserWatchdogStartCommand($thisUser, \'deluged\'', 'pmssUserWatchdogStartCommand($thisUser, \'deluge-web\'', "'deluged start requested'", "'deluge-web start requested'"],
            ],
        ] as $case) {
            $src = $this->pmssReadRepoFile($case[0]);
            foreach ($case[1] as $needle) {
                $this->assertStringContainsString($needle, $src);
            }
        }
    }

    public function testWatchdogsKeepSuspensionAndStartUserLogMessages(): void
    {
        foreach ([
            ['scripts/cron/checkLighttpdInstances.php', 'pmssUserWatchdogHandleSuspended(', "'lighttpd stopped due to suspension'", "'lighttpd start requested'"],
            ['scripts/cron/checkQbittorrentInstances.php', 'pmssUserWatchdogRunEnabledUsers(', "'qbittorrent-nox stopped due to suspension'", "'qbittorrent-nox start requested'"],
            ['scripts/cron/checkRcloneInstances.php', 'pmssUserWatchdogRunEnabledUsers(', "'rclone stopped due to suspension'", "'rclone start requested'"],
            ['scripts/cron/checkDelugeInstances.php', 'pmssUserWatchdogRunEnabledUsers(', "'deluge stopped due to suspension'", "'deluged start requested'"],
        ] as $case) {
            $src = $this->pmssReadRepoFile($case[0]);
            $this->assertStringContainsString($case[1], $src);
            $this->assertStringContainsString($case[2], $src);
            $this->assertStringContainsString($case[3], $src);
        }
    }

    public function testLighttpdWatchdogUsesSharedHelpersAndKeepsRestartFlow(): void
    {
        $src = $this->pmssReadRepoFile('scripts/cron/checkLighttpdInstances.php');

        $this->assertStringContainsString('pmssUserWatchdogProcessRunning($thisUser, \'lighttpd\')', $src);
        $this->assertStringContainsString('pmssUserWatchdogProcessRunning($thisUser, \'php-cgi\')', $src);
        $this->assertStringContainsString('pmssUserWatchdogTerminateProcesses($thisUser, [\'lighttpd\', \'php-cgi\'], 15);', $src);
        $this->assertStringContainsString('pmssUserWatchdogTerminateProcesses($thisUser, [\'lighttpd\', \'php-cgi\'], 9);', $src);
        $this->assertStringContainsString('pmssUserWatchdogStartCommand($thisUser, \'lighttpd\'', $src);
        $this->assertStringContainsString('Killing (if any) lighttpd for user: {$thisUser}', $src);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'lighttpd restart requested');", $src);
        $this->assertStringContainsString('if ($socketError || !$lighttpdRunning) {', $src);
        $this->assertStringContainsString("'lighttpd start requested'", $src);
    }

}
