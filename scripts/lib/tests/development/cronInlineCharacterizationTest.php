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
            $this->pmssAssertRepoFileContainsAllStrings($case[0], $case[1]);
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
            $this->pmssAssertRepoFileContainsAllStrings($case[0], array_slice($case, 1));
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
            $this->assertStringContainsString('pmssUserWatchdogSuCommand($thisUser,', $src);
        }

        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/userLifecycle.php', ['function pmssUserWatchdogSuCommand(', 'escapeshellarg($username)', 'escapeshellarg($innerCommand)']);
    }

    public function testLighttpdWatchdogUsesSharedHelpersAndKeepsRestartFlow(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/checkLighttpdInstances.php', [
            "require_once __DIR__.'/../lib/lighttpd/watchdogSocketProbe.php';",
            "pmssUserLighttpdEnabled(\$thisUser)",
            "pmssLighttpdWatchdogDeleteErrorPage(\$thisUser)",
            'pmssLighttpdWatchdogWriteErrorPage(',
            'pmssLighttpdWatchdogDetectReason(',
            'pmssLighttpdWatchdogSocketProbeWithRetry($socketPath);',
            'pmssUserWatchdogProcessRunning($thisUser, \'php-cgi\')',
            'pmssUserWatchdogRestartProcessesIf(',
            'pmssUserWatchdogTerminateProcesses($thisUser, [\'lighttpd\', \'php-cgi\'], 15);',
            'pmssUserWatchdogTerminateProcesses($thisUser, [\'lighttpd\', \'php-cgi\'], 9);',
            'pmssUserWatchdogEnsureServices($thisUser, [[\'processName\' => \'lighttpd\'',
            'lighttpd disabled by config; terminating web stack',
            'Killing (if any) lighttpd for user: {$thisUser}',
            "'lighttpd restart requested'",
            "'lighttpd start requested'",
        ]);
    }

    public function testServiceWatchdogsUseSharedWatchdogSpecHelpers(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/userLifecycle.php', [
            'function pmssUserWatchdogRestartProcessesIf(',
            'function pmssUserWatchdogEnsureServices(',
            'function pmssUserWatchdogRunService(',
        ]);
    }

}
