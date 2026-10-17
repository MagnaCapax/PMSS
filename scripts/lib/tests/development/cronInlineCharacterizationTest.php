<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CronInlineCharacterizationTest extends TestCase
{
    public function testLighttpdBusyLockDistinguishesAccountCheckFromMinuteRun(): void
    {
        $lockDir = $this->pmssMakeTempDir('pmss-lighttpd-busy-lock-', 0700);
        $lock = fopen($lockDir.'/pmss-checkLighttpdInstances.lock', 'c');
        $this->assertTrue(is_resource($lock));
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            foreach ([['account', 75], [null, 0]] as [$user, $expectedRc]) {
                $result = $this->pmssRunRepoPhpScriptCommand(
                    'scripts/cron/checkLighttpdInstances.php',
                    $user === null ? [] : [$user],
                    ['PMSS_RUNTIME_LOCK_DIR' => $lockDir]
                );
                $this->assertSame($expectedRc, $result['rc'], $result['output']);
                $this->assertSame(1, substr_count($result['output'], 'checkLighttpdInstances already running; skipping'));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testWebPublicCertFailureMarkerUsesSharedWriter(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/cron/webPublicCertsProcess.php', [
            "require_once __DIR__.'/../lib/lighttpd/userFileWrite.php';",
            'pmssReplaceAccountFileWithLegacyFallback($user, $home, $failedMarker, gmdate(\'c\')." rc=$rc\\n", 0644);',
            '@unlink($flag);',
            '@unlink($failedMarker);',
        ], ['@file_put_contents($failedMarker' => 'failure marker must use the shared writer']);
    }

    public function testWebPublicCertActivatesCurrentVhostBeforeCertbot(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/webPublicCertsProcess.php');
        $this->assertOrderedStrings([
            '$names = pmssWebPublicCertNames(',
            "exec('/scripts/util/createNginxConfig.php --user '.escapeshellarg(\$user).' 2>&1', \$configOut, \$configRc);",
            "exec('systemctl reload nginx 2>&1 || /etc/init.d/nginx reload 2>&1', \$reloadOut, \$reloadRc);",
            "\$cmd = 'certbot '.implode(",
        ], $source, 'ACME must use the activated current vhost: ');
        $this->assertStringContainsString('if ($configRc !== 0)', $source);
        $this->assertStringContainsString('if ($reloadRc !== 0)', $source);
    }

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
            'scripts/cron/checkQbittorrentInstances.php' => ['required' => ['$pmssCheckQbittorrentLock = pmssCronLockAcquire(\'checkQbittorrentInstances\', \'pmssCronLockSkipLog\', \'pmssUserWatchdogLockAcquire\')', 'pmssUserWatchdogRunService(', 'pmssUserWatchdogApplyManagedConfigWhenStopped(', 'pmssUserWatchdogRestartProcessesIf(', "'pmssQbittorrentApplyManagedConfig'", 'nohup qbittorrent-nox -d >> /dev/null 2>&1 &', "'qbittorrent-nox stopped due to suspension'", "'qbittorrent-nox start requested'", 'pmssBuildUserServiceShellCommand($thisUser,'], 'forbidden' => ['su '.'{$thisUser}' => 'qBittorrent watchdog must quote su shell boundaries through the shared helper']],
            'scripts/cron/checkRcloneInstances.php' => ['required' => ['$pmssCheckRcloneLock = pmssCronLockAcquire(\'checkRcloneInstances\', \'pmssCronLockSkipLog\', \'pmssUserWatchdogLockAcquire\')', 'pmssUserWatchdogRunService(', '--rc-web-gui --rc-addr 127.0.0.1:{$port}', "'rclone stopped due to suspension'", "'rclone start requested'", 'pmssBuildUserServiceShellCommand($thisUser,'], 'forbidden' => ['su '.'{$thisUser}' => 'rclone watchdog must quote su shell boundaries through the shared helper']],
            'scripts/cron/checkDelugeInstances.php' => ['required' => ['$pmssCheckDelugeLock = pmssCronLockAcquire(\'checkDelugeInstances\', \'pmssCronLockSkipLog\', \'pmssUserWatchdogLockAcquire\')', 'pmssUserWatchdogRunService(', 'pmssUserWatchdogApplyManagedConfigWhenStopped(', 'pmssUserWatchdogRestartProcessesIf(', "'pmssDelugeApplyManagedConfig'", "'deluge stopped due to suspension'", "'deluge restarted to apply upload throttle'", "'deluged start requested'", "'deluge-web start requested'", 'pmssBuildUserServiceShellCommand($thisUser,'], 'forbidden' => ['su '.'{$thisUser}' => 'deluge watchdog must quote su shell boundaries through the shared helper']],
            'scripts/lib/runtime/environment.php' => ['required' => ['function pmssBuildUserShellCommand(', 'escapeshellarg($username)', 'escapeshellarg($command)']],
            'scripts/lib/user/serviceLaunch.php' => ['required' => ['function pmssBuildUserServiceShellCommand(', "'--scope'", "'--slice='.\$slice", "pmssBuildCommand('systemd-run'", "pmssBuildCommand('systemctl', ['start', \$slice])"]],
            'scripts/lib/user/watchdog.php' => ['required' => [
                "pmssRequireRelativeFiles(__DIR__, ['log.php', 'selection.php', 'serviceLaunch.php']);",
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
            "pmssCronLockAcquire('checkLighttpdInstances', \$onBusy, 'pmssUserWatchdogLockAcquire')",
            "require_once __DIR__.'/../lib/lighttpd/watchdogSocketProbe.php';",
            "pmssUserLighttpdEnabled(\$thisUser)",
            "pmssLighttpdWatchdogDeleteErrorPage(\$thisUser, \$watchdogWebRoot)",
            'pmssLighttpdWatchdogWriteErrorPage(',
            'pmssLighttpdWatchdogDetectReason(',
            "'lighttpd watchdog: ' . \$watchdogReason",
            'pmssLighttpdWatchdogSocketProbeWithRetry($socketPath);',
            "pmssLighttpdWatchdogListeningSocketSnapshot(\$homeDir)['paths']",
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
            "pmssCronLockAcquire('checkLighttpdInstances', \$onBusy, 'pmssUserWatchdogLockAcquire')",
            "pmssUserWatchdogServiceSpec('lighttpd'",
        ], $this->pmssReadRepoFile('scripts/cron/checkLighttpdInstances.php'), 'lighttpd shared lock-fd close contract: ');
    }

    public function testMediaStackWatchdogKeepsRootGuardAndUserLoopSingleInstance(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/mediaStackInstancesCheck.php');

        $this->assertOrderedStrings([
            'pmssRootGuardAuditAndKill(',
            "\$pmssMediaStackInstancesLock = pmssCronLockAcquire('mediaStackInstancesCheck'",
            'return $rootGuardFindings > 0 ? 1 : 0;',
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
