<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CronInlineCharacterizationTest extends TestCase
{
    public function testBootTuningUsesSharedManagedPathWrites(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/systemPrep.php', [
            '[$scriptTarget, $scriptRaw, 0755, \'Boot tuning script\']',
            '[$serviceTarget, $serviceRaw, 0644, \'Boot tuning service\']',
            'pmssRefreshManagedPathFile($path, $content, $label, $log, [',
            '\'successMessage\' => \'Installed \'.$label.\' at \'.$path,',
        ], ['$write'.'Target = static function' => 'pmssEnsureBootTuning() should keep its two file writes inline rather than via a local wrapper']);
    }

    public function testServiceWatchdogsUseSharedHelpersAndKeepCommands(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/cron/checkQbittorrentInstances.php' => ['required' => ['pmssUserWatchdogRunService(', 'pmssUserWatchdogApplyManagedConfigWhenStopped(', 'pmssUserWatchdogRestartProcessesIf(', "'pmssQbittorrentApplyManagedConfig'", 'nohup qbittorrent-nox -d >> /dev/null 2>&1 &', "'qbittorrent-nox stopped due to suspension'", "'qbittorrent-nox start requested'", 'pmssUserWatchdogSuCommand($thisUser,'], 'forbidden' => ['su '.'{$thisUser}' => 'qBittorrent watchdog must quote su shell boundaries through the shared helper']],
            'scripts/cron/checkRcloneInstances.php' => ['required' => ['pmssUserWatchdogRunService(', '--rc-web-gui --rc-addr 127.0.0.1:{$port}', "'rclone stopped due to suspension'", "'rclone start requested'", 'pmssUserWatchdogSuCommand($thisUser,'], 'forbidden' => ['su '.'{$thisUser}' => 'rclone watchdog must quote su shell boundaries through the shared helper']],
            'scripts/cron/checkDelugeInstances.php' => ['required' => ['pmssUserWatchdogRunService(', 'pmssUserWatchdogApplyManagedConfigWhenStopped(', 'pmssUserWatchdogRestartProcessesIf(', "'pmssDelugeApplyManagedConfig'", "'deluge stopped due to suspension'", "'deluge restarted to apply upload throttle'", "'deluged start requested'", "'deluge-web start requested'", 'pmssUserWatchdogSuCommand($thisUser,'], 'forbidden' => ['su '.'{$thisUser}' => 'deluge watchdog must quote su shell boundaries through the shared helper']],
            'scripts/lib/runtime/environment.php' => ['required' => ['function pmssBuildUserShellCommand(', 'escapeshellarg($username)', 'escapeshellarg($command)']],
            'scripts/lib/user/watchdog.php' => ['required' => [
                'function pmssUserWatchdogSuCommand(',
                'pmssBuildUserShellCommand($username, $innerCommand)',
                'function pmssUserWatchdogRestartProcessesIf(',
                'function pmssUserWatchdogApplyManagedConfigWhenStopped(',
                'function pmssUserWatchdogServiceSpec(',
                'function pmssUserWatchdogEnsureServices(',
                'function pmssUserWatchdogRunService(',
            ]],
        ]);
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

    public function testWireguardSyncconfRejectsPartialTempfileWrites(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/checkWireguard.php', [
            '$config = (string) $strip[\'stdout\'];',
            '$written = @file_put_contents($tmp, $config);',
            '$secured = @chmod($tmp, 0600);',
            '$written === false || $written !== strlen($config) || !$secured',
            "pmssWireguardCommandCapture('wg', ['syncconf', 'wg0', \$tmp])",
        ]);
    }

}
