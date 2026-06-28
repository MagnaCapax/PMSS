<?php
/**
 * rTorrent cron watchdog support helpers.
 *
 * Keeps checkRtorrent.php focused on per-user state flow while preserving the
 * legacy pmssCheckRtorrent* helper names used by contract tests.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/process.php';
require_once __DIR__.'/../rtorrentConfig.php';
require_once __DIR__.'/../user/log.php';
require_once __DIR__.'/../user/traffic.php';
require_once __DIR__.'/../user/userConfigStore.php';

/** Emit a cron-visible line when debug is enabled or the caller forces it. */
function pmssCheckRtorrentLog(string $message, bool $force = false, bool $debug = false): void
{
    if (!$force && !$debug) return;
    echo date('c').' '.$message."\n";
}

/** Mirror an intervention to cron output and the per-user PMSS log. */
function pmssCheckRtorrentLogBoth(string $user, string $message, bool $debug): void
{
    pmssCheckRtorrentLog($message, true, $debug);
    pmssUserLog($user, 'checkRtorrent: '.$message);
}

/** Clear a stale SCGI socket before restart or grace tracking. */
function pmssCheckRtorrentCleanupStaleSocket(string $user, string $socketPath, string $unresponsiveState, bool $debug): void
{
    rtorrentProcessClearStaleState($unresponsiveState);
    if (!file_exists($socketPath)) return;

    pmssCheckRtorrentLogBoth($user, 'stale socket detected, process not running, cleaning up', $debug);
    if (!@unlink($socketPath) && file_exists($socketPath)) pmssCheckRtorrentLogBoth($user, "stale socket cleanup failed (socket={$socketPath})", $debug);
}

/** Delay starts shortly after reboot so many users do not hit storage at once. */
function pmssCheckRtorrentStaggerAfterRecentReboot(string $user, string $messagePrefix, bool $debug): bool
{
    if (!rtorrentProcessRecentReboot(600)) return false;

    $delay = rtorrentProcessStaggerDelay($user, 300);
    pmssCheckRtorrentLogBoth($user, "{$messagePrefix}staggering start by {$delay}s (post-reboot)", $debug);
    sleep($delay);
    return true;
}

/** Keep alive-but-unresponsive rTorrent handling in one conservative path. */
function pmssCheckRtorrentExtendUnresponsiveGrace(
    string $user,
    string $message,
    string $unresponsiveState,
    string $acceptQueueWedgeState,
    bool $debug
): void {
    rtorrentProcessWriteStateFile($unresponsiveState, (string) time());
    rtorrentProcessClearStaleState($acceptQueueWedgeState);
    pmssCheckRtorrentLogBoth($user, $message, $debug);
}

/** Refresh an out-of-date executor wrapper from the skeleton copy. */
function pmssCheckRtorrentRefreshExecutorFromSkel(string $user, string $home, bool $debug): void
{
    $skelScript = '/etc/skel/.rtorrentExecute.php';
    $userScript = rtrim($home, '/').'/.rtorrentExecute.php';
    if (!is_file($skelScript) || !is_file($userScript)) return;

    $skelHash = @md5_file($skelScript);
    $userHash = @md5_file($userScript);
    if (!is_string($skelHash) || !is_string($userHash)) {
        pmssCheckRtorrentLogBoth($user, 'executor refresh skipped (checksum unavailable)', $debug);
        return;
    }
    if ($skelHash === $userHash) return;

    if (!@copy($skelScript, $userScript)) {
        pmssCheckRtorrentLogBoth($user, 'executor refresh failed (copy error)', $debug);
        return;
    }
    if (!@chown($userScript, $user)) {
        pmssCheckRtorrentLogBoth($user, 'refreshed stale executor from skel (ownership update failed)', $debug);
        return;
    }
    pmssCheckRtorrentLogBoth($user, 'refreshed stale executor from skel', $debug);
}

/** Apply the persisted upload throttle only when one has been configured. */
function pmssCheckRtorrentApplyThrottle(string $user, string $socketPath, bool $debug): void
{
    $throttle = pmssReadTorrentThrottle($user);
    if ($throttle === null) return;

    $throttleValue = $throttle > 0 ? $throttle : 0;
    if (rtorrentScgiCall($socketPath, 'throttle.global_up.max_rate.set', [$throttleValue], 5) === false) {
        pmssCheckRtorrentLogBoth($user, 'failed to apply upload throttle', $debug);
        return;
    }

    pmssCheckRtorrentLog("Applied upload throttle (up={$throttleValue} KiB/s) for {$user}", false, $debug);
}

/** Publish or clear the ownership drift report without hiding write failures. */
function pmssCheckRtorrentPublishChangedConfigReport(array $changedConfig, string $path, bool $debug): void
{
    if (!pmssPathTargetIsSafe($path, false, true, false)) {
        pmssCheckRtorrentLog("WARN: unsafe changed config report path: {$path}", true, $debug);
        return;
    }

    if ($changedConfig !== array()) {
        if (@file_put_contents($path, implode("\n", $changedConfig)) === false) {
            pmssCheckRtorrentLog("WARN: failed to write changed config report: {$path}", true, $debug);
        }
        return;
    }

    if (file_exists($path) && !@unlink($path) && file_exists($path)) {
        pmssCheckRtorrentLog("WARN: failed to remove stale changed config report: {$path}", true, $debug);
    }
}

/** Rebuild a missing per-user rTorrent config from the canonical templates. */
function pmssCheckRtorrentRecoverMissingConfig(string $user, string $home, bool $debug): bool
{
    if (!is_dir($home)) return false;

    pmssCheckRtorrentLogBoth($user, 'missing .rtorrent.rc detected; regenerating', $debug);
    $userConfigStore = new UserConfigStore();
    $payload = $userConfigStore->applyFallbacks($user, is_array($payload = $userConfigStore->get($user)) ? $payload : []);
    $ramMiB = (int) ($payload['ramMiB'] ?? 0);
    if ($ramMiB <= 0) {
        pmssCheckRtorrentLogBoth($user, 'missing .rtorrent.rc recovery skipped (unable to resolve ramMiB)', $debug);
        return false;
    }

    $dhtDefault = @file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.dht');
    $pexDefault = @file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.pex');
    if (!is_string($dhtDefault) || !is_string($pexDefault)) {
        pmssCheckRtorrentLogBoth($user, 'missing .rtorrent.rc recovery failed (defaults unavailable)', $debug);
        return false;
    }

    $resources = is_file('/etc/seedbox/config/system.rtorrent.resources')
        ? (pmssReadSerializedArrayFile('/etc/seedbox/config/system.rtorrent.resources') ?? [])
        : [];
    $configInput = ['ram' => $ramMiB, 'dht' => $dhtDefault, 'pex' => $pexDefault, 'uploadThrottle' => (($throttle = pmssReadTorrentThrottle($user)) === null) ? 0 : $throttle];
    if (isset($payload['rtorrentPort']) && is_numeric($payload['rtorrentPort']) && (int) $payload['rtorrentPort'] > 0) {
        $configInput['scgiPort'] = (int) $payload['rtorrentPort'];
    }

    try {
        $rtorrentConfig = new rtorrentConfig($resources);
        $configuration = $rtorrentConfig->createConfig($configInput);
        if (!$rtorrentConfig->writeConfig($user, $configuration['configFile'])) {
            pmssCheckRtorrentLogBoth($user, 'missing .rtorrent.rc recovery failed (write error)', $debug);
            return false;
        }
    } catch (Throwable $exception) {
        pmssCheckRtorrentLogBoth($user, 'missing .rtorrent.rc recovery failed: '.$exception->getMessage(), $debug);
        return false;
    }

    pmssCheckRtorrentLogBoth($user, 'missing .rtorrent.rc recovered', $debug);
    return true;
}

require_once __DIR__.'/watchdogProcessFlow.php';
