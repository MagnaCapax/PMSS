<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CronInlineCharacterizationTest extends TestCase
{
    public function testBootTuningUsesSharedManagedPathWrites(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/systemPrep.php');
        $wrapperNeedle = '$write'.'Target = static function';

        $this->assertTrue(
            strpos($src, $wrapperNeedle) === false,
            'pmssEnsureBootTuning() should keep its two file writes inline rather than via a local wrapper'
        );
        $this->assertStringContainsString('[$scriptTarget, $scriptRaw, 0755, \'Boot tuning script\']', $src);
        $this->assertStringContainsString('[$serviceTarget, $serviceRaw, 0644, \'Boot tuning service\']', $src);
        $this->assertStringContainsString('pmssWriteManagedPathFile($path, $content, $label, $log, null, null, $mode', $src);
        $this->assertStringContainsString('$log(\'Installed \'.$label.\' at \'.$path);', $src);
    }

    public function testServiceWatchdogsUseSharedHelpersAndKeepCommands(): void
    {
        foreach ([
            [
                'scripts/cron/checkQbittorrentInstances.php',
                ['pmssUserWatchdogRunService(', 'pmssUserWatchdogRestartProcessesIf(', 'pmssQbittorrentApplyManagedConfig(', 'nohup qbittorrent-nox -d >> /dev/null 2>&1 &', "'qbittorrent-nox stopped due to suspension'", "'qbittorrent-nox start requested'"],
            ],
            [
                'scripts/cron/checkRcloneInstances.php',
                ['pmssUserWatchdogRunService(', '--rc-web-gui --rc-addr 127.0.0.1:{$port}', "'rclone stopped due to suspension'", "'rclone start requested'"],
            ],
            [
                'scripts/cron/checkDelugeInstances.php',
                ['pmssUserWatchdogRunService(', 'pmssUserWatchdogRestartProcessesIf(', 'pmssDelugeApplyManagedConfig(', "'deluge stopped due to suspension'", "'deluge restarted to apply upload throttle'", "'deluged start requested'", "'deluge-web start requested'"],
            ],
        ] as $case) {
            $this->pmssAssertRepoFileContainsAllStrings($case[0], $case[1]);
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

        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/watchdog.php', ['function pmssUserWatchdogSuCommand(', 'escapeshellarg($username)', 'escapeshellarg($innerCommand)']);
    }

    public function testLighttpdWatchdogUsesSharedHelpersAndKeepsRestartFlow(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/checkLighttpdInstances.php', [
            "require_once __DIR__.'/../lib/lighttpd/watchdogSocketProbe.php';",
            "pmssUserLighttpdEnabled(\$thisUser)",
            "pmssLighttpdWatchdogDeleteErrorPage(\$thisUser, \$watchdogWebRoot)",
            'pmssLighttpdWatchdogWriteErrorPage(',
            'pmssLighttpdWatchdogDetectReason(',
            'pmssLighttpdWatchdogSocketProbeWithRetry($socketPath);',
            'pmssUserWatchdogHandleSuspended(',
            "'lighttpd stopped due to suspension'",
            'pmssUserWatchdogProcessRunning($thisUser, \'php-cgi\')',
            'pmssUserWatchdogRestartProcessesIf(',
            'pmssUserWatchdogTerminateProcesses($thisUser, [\'lighttpd\', \'php-cgi\'], 15);',
            'pmssUserWatchdogTerminateProcesses($thisUser, [\'lighttpd\', \'php-cgi\'], 9);',
            "pmssUserWatchdogServiceSpec('lighttpd'",
            'lighttpd disabled by config; terminating web stack',
            'Killing (if any) lighttpd for user: {$thisUser}',
            "'lighttpd restart requested'",
            "'lighttpd start requested'",
        ]);
    }

    public function testServiceWatchdogsUseSharedWatchdogSpecHelpers(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/watchdog.php', [
            'function pmssUserWatchdogRestartProcessesIf(',
            'function pmssUserWatchdogServiceSpec(',
            'function pmssUserWatchdogEnsureServices(',
            'function pmssUserWatchdogRunService(',
        ]);
    }

}
