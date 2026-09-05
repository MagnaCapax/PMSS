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
            'pmssRefreshManagedPathFile($path, $content, $label, $log, pmssManagedPathInstallOptions(',
        ], ['$write'.'Target = static function' => 'pmssEnsureBootTuning() should keep its two file writes inline rather than via a local wrapper']);
    }

    public function testServiceWatchdogsUseSharedHelpersAndKeepCommands(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/cron/checkQbittorrentInstances.php' => ['required' => ['$pmssCheckQbittorrentLock = pmssUserWatchdogLockAcquire(pmssRuntimeLockPath(\'pmss-checkQbittorrentInstances.lock\'))', 'if ($pmssCheckQbittorrentLock === false) {', 'checkQbittorrentInstances already running; skipping', 'pmssUserWatchdogRunService(', 'pmssUserWatchdogApplyManagedConfigWhenStopped(', 'pmssUserWatchdogRestartProcessesIf(', "'pmssQbittorrentApplyManagedConfig'", 'nohup qbittorrent-nox -d >> /dev/null 2>&1 &', "'qbittorrent-nox stopped due to suspension'", "'qbittorrent-nox start requested'", 'pmssUserWatchdogSuCommand($thisUser,'], 'forbidden' => ['su '.'{$thisUser}' => 'qBittorrent watchdog must quote su shell boundaries through the shared helper']],
            'scripts/cron/checkRcloneInstances.php' => ['required' => ['$pmssCheckRcloneLock = pmssUserWatchdogLockAcquire(pmssRuntimeLockPath(\'pmss-checkRcloneInstances.lock\'))', 'if ($pmssCheckRcloneLock === false) {', 'checkRcloneInstances already running; skipping', 'pmssUserWatchdogRunService(', '--rc-web-gui --rc-addr 127.0.0.1:{$port}', "'rclone stopped due to suspension'", "'rclone start requested'", 'pmssUserWatchdogSuCommand($thisUser,'], 'forbidden' => ['su '.'{$thisUser}' => 'rclone watchdog must quote su shell boundaries through the shared helper']],
            'scripts/cron/checkDelugeInstances.php' => ['required' => ['$pmssCheckDelugeLock = pmssUserWatchdogLockAcquire(pmssRuntimeLockPath(\'pmss-checkDelugeInstances.lock\'))', 'if ($pmssCheckDelugeLock === false) {', 'checkDelugeInstances already running; skipping', 'pmssUserWatchdogRunService(', 'pmssUserWatchdogApplyManagedConfigWhenStopped(', 'pmssUserWatchdogRestartProcessesIf(', "'pmssDelugeApplyManagedConfig'", "'deluge stopped due to suspension'", "'deluge restarted to apply upload throttle'", "'deluged start requested'", "'deluge-web start requested'", 'pmssUserWatchdogSuCommand($thisUser,'], 'forbidden' => ['su '.'{$thisUser}' => 'deluge watchdog must quote su shell boundaries through the shared helper']],
            'scripts/lib/runtime/environment.php' => ['required' => ['function pmssBuildUserShellCommand(', 'escapeshellarg($username)', 'escapeshellarg($command)']],
            'scripts/lib/user/serviceLaunch.php' => ['required' => ['function pmssBuildUserServiceShellCommand(', "'--scope'", "'--slice='.\$slice", "pmssBuildCommand('systemd-run'", "pmssBuildCommand('systemctl', ['start', \$slice])"]],
            'scripts/lib/user/watchdog.php' => ['required' => [
                'function pmssUserWatchdogSuCommand(',
                'pmssBuildUserServiceShellCommand($username, $innerCommand)',
                'function pmssUserWatchdogLockAcquire(',
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
            "require_once __DIR__.'/../lib/runtime.php';",
            "pmssUserWatchdogLockAcquire(pmssRuntimeLockPath('pmss-checkLighttpdInstances.lock'))",
            'checkLighttpdInstances already running; skipping',
            "require_once __DIR__.'/../lib/lighttpd/watchdogSocketProbe.php';",
            "pmssUserLighttpdEnabled(\$thisUser)",
            "pmssLighttpdWatchdogDeleteErrorPage(\$thisUser, \$watchdogWebRoot)",
            'pmssLighttpdWatchdogWriteErrorPage(',
            'pmssLighttpdWatchdogDetectReason(',
            "'lighttpd watchdog: ' . \$watchdogReason",
            'pmssLighttpdWatchdogSocketProbeWithRetry($socketPath);',
            'pmssLighttpdWatchdogListeningSocketPaths($homeDir)',
            'pmssLighttpdWatchdogRestartVerify($homeDir, $socketPaths)',
            '$restartVerification[\'status\'] !== \'healthy\'',
            'pmssLighttpdWatchdogSocketFailureIsStaleIndex(',
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
        $this->assertOrderedStrings([
            "pmssUserWatchdogLockAcquire(pmssRuntimeLockPath('pmss-checkLighttpdInstances.lock'))",
            "pmssUserWatchdogServiceSpec('lighttpd'",
        ], $this->pmssReadRepoFile('scripts/cron/checkLighttpdInstances.php'), 'lighttpd shared lock-fd close contract: ');
    }

    public function testMediaStackWatchdogKeepsRootGuardAndUserLoopSingleInstance(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/mediaStackInstancesCheck.php');

        $this->assertOrderedStrings([
            'pmssRootGuardAuditAndKill(',
            "\$pmssMediaStackInstancesLock = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-mediaStackInstancesCheck.lock'), true);",
            'if ($pmssMediaStackInstancesLock === false) {',
            'mediaStackInstancesCheck already running; skipping',
            "foreach (\$result['users'] as \$username) {",
        ], $source, 'media-stack watchdog guard contract: ');
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
