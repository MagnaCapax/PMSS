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
                ['pmssUserWatchdogRunService(', 'pmssUserWatchdogRestartProcessesIf(', 'nohup qbittorrent-nox -d >> /dev/null 2>&1 &', "'qbittorrent-nox start requested'"],
            ],
            [
                'scripts/cron/checkRcloneInstances.php',
                ['pmssUserWatchdogRunService(', '--rc-web-gui --rc-addr 127.0.0.1:{$port}', "'rclone start requested'"],
            ],
            [
                'scripts/cron/checkDelugeInstances.php',
                ['pmssUserWatchdogRunService(', 'pmssUserWatchdogRestartProcessesIf(', "'deluge restarted to apply upload throttle'", "'deluged start requested'", "'deluge-web start requested'"],
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
            ['scripts/cron/checkQbittorrentInstances.php', 'pmssUserWatchdogRunService(', "'qbittorrent-nox stopped due to suspension'", "'qbittorrent-nox start requested'"],
            ['scripts/cron/checkRcloneInstances.php', 'pmssUserWatchdogRunService(', "'rclone stopped due to suspension'", "'rclone start requested'"],
            ['scripts/cron/checkDelugeInstances.php', 'pmssUserWatchdogRunService(', "'deluge stopped due to suspension'", "'deluged start requested'"],
        ] as $case) {
            $src = $this->pmssReadRepoFile($case[0]);
            $this->assertStringContainsString($case[1], $src);
            $this->assertStringContainsString($case[2], $src);
            $this->assertStringContainsString($case[3], $src);
        }
    }

    public function testServiceWatchdogsQuoteSuShellBoundaries(): void
    {
        foreach ([
            'scripts/cron/checkQbittorrentInstances.php',
            'scripts/cron/checkRcloneInstances.php',
            'scripts/cron/checkDelugeInstances.php',
        ] as $path) {
            $src = $this->pmssReadRepoFile($path);
            $unsafeNeedle = 'su '.'{$thisUser}';
            $this->assertStringNotContainsString($unsafeNeedle, $src);
            $this->assertStringContainsString('escapeshellarg($thisUser)', $src);
            $this->assertStringContainsString('escapeshellarg($innerCommand)', $src);
        }
    }

    public function testLighttpdWatchdogUsesSharedHelpersAndKeepsRestartFlow(): void
    {
        $src = $this->pmssReadRepoFile('scripts/cron/checkLighttpdInstances.php');

        $this->assertStringContainsString("require_once __DIR__.'/../lib/lighttpd/watchdogSocketProbe.php';", $src);
        $this->assertStringContainsString("pmssUserLighttpdEnabled(\$thisUser)", $src);
        $this->assertStringContainsString("pmssLighttpdWatchdogDeleteErrorPage(\$thisUser)", $src);
        $this->assertStringContainsString('pmssLighttpdWatchdogWriteErrorPage(', $src);
        $this->assertStringContainsString('pmssLighttpdWatchdogDetectReason(', $src);
        $this->assertStringContainsString('pmssLighttpdWatchdogSocketProbeWithRetry($socketPath);', $src);
        $this->assertStringContainsString('pmssUserWatchdogProcessRunning($thisUser, \'php-cgi\')', $src);
        $this->assertStringContainsString('pmssUserWatchdogRestartProcessesIf(', $src);
        $this->assertStringContainsString('pmssUserWatchdogTerminateProcesses($thisUser, [\'lighttpd\', \'php-cgi\'], 15);', $src);
        $this->assertStringContainsString('pmssUserWatchdogTerminateProcesses($thisUser, [\'lighttpd\', \'php-cgi\'], 9);', $src);
        $this->assertStringContainsString('pmssUserWatchdogEnsureServices($thisUser, [[\'processName\' => \'lighttpd\'', $src);
        $this->assertStringContainsString('lighttpd disabled by config; terminating web stack', $src);
        $this->assertStringContainsString('Killing (if any) lighttpd for user: {$thisUser}', $src);
        $this->assertStringContainsString("'lighttpd restart requested'", $src);
        $this->assertStringContainsString("'lighttpd start requested'", $src);
    }

    public function testServiceWatchdogsUseSharedWatchdogSpecHelpers(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/userLifecycle.php');

        $this->assertStringContainsString('function pmssUserWatchdogRestartProcessesIf(', $src);
        $this->assertStringContainsString('function pmssUserWatchdogEnsureServices(', $src);
        $this->assertStringContainsString('function pmssUserWatchdogRunService(', $src);
    }

}
