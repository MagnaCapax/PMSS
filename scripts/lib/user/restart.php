<?php
/** Account preflight, locks, and ordered service starts for restartUser. */
require_once __DIR__.'/restartProcesses.php';
require_once dirname(__DIR__).'/userLifecycle.php';
require_once dirname(__DIR__).'/rtorrent/process.php';

/** Return a refusal reason for an unsafe account home, or null when ready. */
function pmssRestartUserHomeRefusal(string $user, int $uid, string $home): ?string
{
    clearstatcache(true, $home);
    if (is_dir($home.'/www-disabled')) return 'account suspended';
    if (is_link($home) || !is_dir($home) || realpath($home) !== $home || fileowner($home) !== $uid) {
        return 'account home is missing or not owned by the account';
    }
    return null;
}

/** @return array{recreate:resource,restart:resource}|null */
function pmssRestartUserLocksAcquire(string $user, ?string &$reason): ?array
{
    $busy = false;
    // Claim restart first so a duplicate run gets its idempotent success result.
    $restart = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-userRestart-'.$user.'.lock'), true, 'c', false, true, $busy);
    if ($restart === false) {
        $reason = $busy ? 'restart already running' : 'restart lock unavailable';
        return null;
    }
    $recreate = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-userRecreate-'.$user.'.lock'), true, 'c', false, true, $busy);
    if ($recreate === false) {
        fclose($restart);
        $reason = $busy ? 'rebuild in progress' : 'rebuild lock unavailable';
        return null;
    }
    return ['recreate' => $recreate, 'restart' => $restart];
}

/** Prevent detached child services from retaining either PHP lock descriptor. */
function pmssRestartUserLocksPrepareChildren(array $locks): void
{
    $fds = array_merge(pmssLockHandleFdList($locks['recreate']), pmssLockHandleFdList($locks['restart']));
    putenv($fds === [] ? PMSS_UPDATE_LOCK_FDS_ENV : PMSS_UPDATE_LOCK_FDS_ENV.'='.implode(',', array_unique($fds)));
}

/** Run a fixed root-owned launcher; customer-writable scripts never run as root. */
function pmssRestartUserRootStart(string $launcher, string $user): bool
{
    if (!in_array($launcher, ['startRtorrent', 'startLighttpd'], true)) return false;
    $rc = 1;
    $command = pmssLockChildClosePrefix().escapeshellarg('/scripts/'.$launcher).' '.escapeshellarg($user).' >/dev/null 2>&1';
    exec($command, $output, $rc);
    return $rc === 0;
}

/** Confirm a launcher left the expected account-owned daemon running. */
function pmssRestartUserServiceRunning(int $uid, string $comm, string $procRoot = '/proc'): bool
{
    foreach (pmssRestartUserProcessPids($uid, $procRoot) as $pid) {
        if (trim((string) @file_get_contents(rtrim($procRoot, '/').'/'.$pid.'/comm')) === $comm) return true;
    }
    return false;
}

/** Wait for the account's rTorrent PID to survive the detached launch. */
function pmssRestartUserRtorrentRunning(string $user, float $startupSeconds = 5.0, float $stableSeconds = 1.0): bool
{
    return rtorrentProcessWaitForStablePids(
        static function () use ($user): array { return pmssUserWatchdogProcessPids($user, '^rtorrent'); },
        $startupSeconds,
        $stableSeconds
    );
}

/** Bound only the account-owned media-stack start while the root locks are held. */
function pmssRestartUserMediaStackCommandBuild(string $user, string $home): string
{
    return pmssLockChildClosePrefix().'/usr/bin/timeout --kill-after=10 300 '
        .pmssBuildUserShellCommand($user, pmssMediaStackPanelRecoveryCommandBuild($home, $user)).' 2>/dev/null';
}

/** @return array{started:array<int,string>,failed:array<int,string>} */
function pmssRestartUserServicesStart(string $user, string $home, int $uid): array
{
    $started = [];
    $failed = [];
    // A watchdog can start the daemon first, making our launcher report failure.
    pmssRestartUserRootStart('startRtorrent', $user);
    if (pmssRestartUserRtorrentRunning($user)) $started[] = 'rtorrent';
    else $failed[] = 'rtorrent';

    if (is_file($home.'/.media-stack-status.json')) {
        $rc = 1;
        $output = [];
        try {
            require_once dirname(__DIR__).'/mediaStackRecoveryCommand.php';
            $command = pmssRestartUserMediaStackCommandBuild($user, $home);
            exec($command, $output, $rc);
        } catch (\Throwable $error) {
            $rc = 1;
        }
        if ($rc === 0 && trim(implode("\n", $output)) === 'pmss-media-stack-started') $started[] = 'media-stack';
        else $failed[] = 'media-stack';
    }

    pmssRestartUserRootStart('startLighttpd', $user);
    $lighttpdRunning = pmssRestartUserServiceRunning($uid, 'lighttpd');
    // Give either starter five seconds to make the account-owned daemon visible.
    for ($attempt = 0; $attempt < 25 && !$lighttpdRunning; $attempt++) {
        usleep(200000);
        $lighttpdRunning = pmssRestartUserServiceRunning($uid, 'lighttpd');
    }
    if ($lighttpdRunning) $started[] = 'lighttpd';
    else $failed[] = 'lighttpd';
    return ['started' => $started, 'failed' => $failed];
}

/** Run one validated restart; caller retains both locks until audit logging finishes. */
function pmssRestartUserRun(int $argc, ?string $user, array &$record, &$locks): array
{
    if ($argc !== 2) return ['Usage: restartUser.php USERNAME', 1];
    if ($user === null) return ['invalid username', 1];
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) return ['root privileges required', 1];

    $account = pmssUserAccountLookup($user);
    if (!is_array($account) || !isset($account['uid']) || (int) $account['uid'] < 1) {
        return ['account missing or invalid', 1];
    }
    $reason = null;
    $locks = pmssRestartUserLocksAcquire($user, $reason);
    if ($locks === null) {
        if ($reason === 'restart already running') {
            $record['outcome'] = 'already_running';
            return [$reason, 0];
        }
        return [(string) $reason, 1];
    }
    pmssRestartUserLocksPrepareChildren($locks);
    $home = '/home/'.$user;
    $uid = (int) $account['uid'];
    $reason = pmssRestartUserHomeRefusal($user, $uid, $home);
    if ($reason !== null) return [$reason, 1];

    $stopped = pmssRestartUserProcessesStop($user, $uid);
    $record['signalled_term'] = $stopped['term'];
    $record['signalled_kill'] = $stopped['kill'];
    $starts = pmssRestartUserServicesStart($user, $home, $uid);
    $record['started'] = $starts['started'];
    $record['failed'] = $starts['failed'];
    if ($stopped['remaining'] > 0) $record['failed'][] = 'stop';
    if ($record['failed'] !== []) {
        $record['outcome'] = 'failed';
        return ['restart failed: '.implode(', ', $record['failed']), 1];
    }
    $record['outcome'] = 'success';
    return ['restart completed', 0];
}
