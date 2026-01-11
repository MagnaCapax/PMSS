#!/usr/bin/env php
<?php
/**
 * checkRtorrent.php
 *
 * Cron watchdog for per-user rTorrent instances.
 *
 * Goals:
 * - Default quiet: log only when taking action or encountering anomalies.
 * - Prevent duplicate rTorrent/executor spawns by avoiding "executor present but
 *   rTorrent missing" immediate restarts (race window after crashes).
 * - Provide before/after per-user process snapshots when intervening so future
 *   incidents are diagnosable from logs.
 *
 * Usage:
 *   /scripts/cron/checkRtorrent.php [--debug]
 */

require_once __DIR__.'/../lib/user/log.php';
$lifecycle = __DIR__.'/../lib/userLifecycle.php';
if (is_file($lifecycle)) {
    require_once $lifecycle;
}

$args = isset($argv) ? $argv : (isset($_SERVER['argv']) ? $_SERVER['argv'] : []);
$debug = in_array('--debug', $args, true);

// Some hosts may not expose signal constants (pcntl); keep this script working
// by defining numeric fallbacks.
if (!defined('SIGTERM')) {
    define('SIGTERM', 15);
}
if (!defined('SIGKILL')) {
    define('SIGKILL', 9);
}

/**
 * Emit a log line to stdout (captured by cron redirection).
 */
function pmssCheckRtorrentLog(string $message, bool $force = false, bool $debug = false): void
{
    if (!$force && !$debug) {
        return;
    }
    echo date('c').' '.$message."\n";
}

/**
 * Validate a username using the shared validator when available.
 */
function pmssCheckRtorrentUsernameIsValid(string $user): bool
{
    if (function_exists('pmssValidateUsername')) {
        return pmssValidateUsername($user);
    }
    return (bool) preg_match('/^[a-z][a-z0-9]{0,7}$/', $user);
}

/**
 * List PIDs for a user's exact process name (comm).
 *
 * @return int[]
 */
function pmssCheckRtorrentPgrepExact(string $user, string $comm): array
{
    $out = [];
    $rc = 1;
    @exec('pgrep -u '.escapeshellarg($user).' -x '.escapeshellarg($comm), $out, $rc);
    if ($rc !== 0) {
        return [];
    }
    $pids = [];
    foreach ($out as $line) {
        $pid = (int) trim((string) $line);
        if ($pid > 0) {
            $pids[] = $pid;
        }
    }
    return $pids;
}

/**
 * List PIDs for a user by matching the full command line.
 *
 * @return int[]
 */
function pmssCheckRtorrentPgrepFull(string $user, string $pattern): array
{
    $out = [];
    $rc = 1;
    @exec('pgrep -u '.escapeshellarg($user).' -f '.escapeshellarg($pattern), $out, $rc);
    if ($rc !== 0) {
        return [];
    }
    $pids = [];
    foreach ($out as $line) {
        $pid = (int) trim((string) $line);
        if ($pid > 0) {
            $pids[] = $pid;
        }
    }
    return $pids;
}

/**
 * Locate executor-related processes, separating the php wrapper from the
 * detached screen session.
 *
 * Healthy rTorrent typically includes BOTH:
 * - screen (comm: SCREEN) and
 * - php (comm: php/phpX.Y) running .rtorrentExecute.php
 *
 * @return array{php:int[],screen:int[],all:int[]}
 */
function pmssCheckRtorrentExecutorPids(string $user): array
{
    $out = [];
    $rc = 0;
    @exec('ps -u '.escapeshellarg($user).' -o pid=,comm=,args=', $out, $rc);
    if ($rc !== 0) {
        return ['php' => [], 'screen' => [], 'all' => []];
    }

    $php = [];
    $screen = [];
    $all = [];
    foreach ($out as $line) {
        $line = trim((string) $line);
        if ($line === '' || strpos($line, 'rtorrentExecute.php') === false) {
            continue;
        }
        if (!preg_match('/^(\\d+)\\s+(\\S+)\\s+(.+)$/', $line, $m)) {
            continue;
        }
        $pid = (int) $m[1];
        if ($pid <= 0) {
            continue;
        }
        $comm = strtolower((string) $m[2]);
        $all[] = $pid;
        if (strpos($comm, 'php') === 0) {
            $php[] = $pid;
        } elseif (strpos($comm, 'screen') !== false) {
            $screen[] = $pid;
        }
    }
    return ['php' => $php, 'screen' => $screen, 'all' => $all];
}

/**
 * Capture a process snapshot for a user.
 *
 * @return string[]
 */
function pmssCheckRtorrentPsSnapshot(string $user): array
{
    $out = [];
    $rc = 0;
    @exec(
        'ps -u '.escapeshellarg($user).' -o pid=,ppid=,stat=,lstart=,comm=,args=',
        $out,
        $rc
    );
    if ($rc !== 0) {
        return ['[WARN] ps failed (rc='.$rc.')'];
    }
    $lines = [];
    foreach ($out as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return $lines;
}

/**
 * Best-effort terminate PIDs. Keep implementation simple and safe.
 *
 * @param int[] $pids
 */
function pmssCheckRtorrentKillPids(array $pids, int $signal): void
{
    foreach ($pids as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0) {
            continue;
        }
        if (function_exists('posix_kill')) {
            @posix_kill($pid, $signal);
        } else {
            @exec('kill -'.(int) $signal.' '.(int) $pid);
        }
    }
}

/**
 * Ensure state directory exists for per-user anomaly tracking.
 */
function pmssCheckRtorrentStateDir(): string
{
    $dir = '/run/pmss';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

// Avoid concurrent watchdog executions (cron overlap, manual runs).
$lockPath = (is_dir('/run/lock') ? '/run/lock' : '/tmp').'/pmss-checkRtorrent.lock';
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    pmssCheckRtorrentLog('checkRtorrent already running; skipping', false, $debug);
    exit(0);
}

pmssCheckRtorrentLog('Checking rTorrent instances', false, $debug);

$usersOut = [];
$usersRc = 0;
@exec('/scripts/listUsers.php', $usersOut, $usersRc);
if ($usersRc !== 0) {
    pmssCheckRtorrentLog('ERROR: listUsers.php failed (rc='.$usersRc.'); aborting run', true, $debug);
    exit(1);
}

$changedConfig = [];
$stateDir = pmssCheckRtorrentStateDir();

foreach ($usersOut as $line) {
    $user = trim((string) $line);
    if ($user === '') {
        continue;
    }
    if (!pmssCheckRtorrentUsernameIsValid($user)) {
        pmssCheckRtorrentLog("Skipping invalid username from listUsers output: {$user}", false, $debug);
        continue;
    }

    $home = '/home/'.$user;
    if (!is_dir($home)) {
        pmssCheckRtorrentLog("Skipping {$user}: home missing ({$home})", false, $debug);
        continue;
    }

    // If user is suspended, stop any running processes and move on.
    if (file_exists($home.'/www-disabled') || !is_dir($home.'/www')) {
        $null = [];
        $rc = 0;
        @exec('killall -9 -u '.escapeshellarg($user), $null, $rc);
        pmssCheckRtorrentLog("User {$user} suspended; killall -9 -u {$user} (rc={$rc})", true, $debug);
        if (function_exists('pmssUserLog')) {
            pmssUserLog($user, "checkRtorrent: suspended cleanup (killall rc={$rc})");
        }
        continue;
    }

    // Only manage users with an rTorrent config.
    if (!is_file($home.'/.rtorrent.rc')) {
        continue;
    }

    $executor = pmssCheckRtorrentExecutorPids($user);
    $executorPhpPids = $executor['php'];
    $executorScreenPids = $executor['screen'];
    $executorAllPids = $executor['all'];
    $rtorrentPids = pmssCheckRtorrentPgrepExact($user, 'rtorrent');
    $executorPresent = !empty($executorPhpPids);

    $missingState = $stateDir.'/checkRtorrent-executor-present-rtorrent-missing-'.$user.'.ts';
    if (!empty($rtorrentPids) || !$executorPresent) {
        if (is_file($missingState)) {
            @unlink($missingState);
        }
    }

    // Multiple executor/rtorrent processes are always anomalous; converge to one.
    if (count($executorPhpPids) > 1 || count($executorScreenPids) > 1 || count($rtorrentPids) > 1) {
        pmssCheckRtorrentLog(
            "Anomaly for {$user}: php_exec=".count($executorPhpPids).' screen_exec='.count($executorScreenPids)
                .' rtorrent='.count($rtorrentPids).'; restarting cleanly',
            true,
            $debug
        );
        if (function_exists('pmssUserLog')) {
            pmssUserLog(
                $user,
                'checkRtorrent: anomaly detected; restarting (php_exec='.count($executorPhpPids)
                    .' screen_exec='.count($executorScreenPids).' rtorrent='.count($rtorrentPids).')'
            );
        }

        $before = pmssCheckRtorrentPsSnapshot($user);
        pmssCheckRtorrentLog("Process snapshot BEFORE ({$user})", true, $debug);
        foreach ($before as $row) {
            pmssCheckRtorrentLog($row, true, $debug);
        }

        // Stop processes (best-effort): terminate then hard-kill after a grace period.
        pmssCheckRtorrentKillPids(array_merge($rtorrentPids, $executorAllPids), SIGTERM);
        sleep(3);
        $rtorrentPids = pmssCheckRtorrentPgrepExact($user, 'rtorrent');
        $executorAllPids = pmssCheckRtorrentExecutorPids($user)['all'];
        pmssCheckRtorrentKillPids(array_merge($rtorrentPids, $executorAllPids), SIGKILL);
        sleep(1);

        $rc = 0;
        @passthru('/scripts/startRtorrent '.escapeshellarg($user), $rc);
        pmssCheckRtorrentLog("startRtorrent {$user} completed (rc={$rc})", true, $debug);

        $after = pmssCheckRtorrentPsSnapshot($user);
        pmssCheckRtorrentLog("Process snapshot AFTER ({$user})", true, $debug);
        foreach ($after as $row) {
            pmssCheckRtorrentLog($row, true, $debug);
        }

        continue;
    }

    // No executor and no rTorrent: start.
    if (!$executorPresent && empty($rtorrentPids)) {
        pmssCheckRtorrentLog("rTorrent missing for {$user}; starting", true, $debug);
        if (function_exists('pmssUserLog')) {
            pmssUserLog($user, 'checkRtorrent: rTorrent missing; starting');
        }

        $before = pmssCheckRtorrentPsSnapshot($user);
        pmssCheckRtorrentLog("Process snapshot BEFORE ({$user})", true, $debug);
        foreach ($before as $row) {
            pmssCheckRtorrentLog($row, true, $debug);
        }

        $rc = 0;
        @passthru('/scripts/startRtorrent '.escapeshellarg($user), $rc);
        pmssCheckRtorrentLog("startRtorrent {$user} completed (rc={$rc})", true, $debug);

        $after = pmssCheckRtorrentPsSnapshot($user);
        pmssCheckRtorrentLog("Process snapshot AFTER ({$user})", true, $debug);
        foreach ($after as $row) {
            pmssCheckRtorrentLog($row, true, $debug);
        }

        continue;
    }

    // Executor present but rTorrent missing: likely a transient crash/restart
    // window. Only intervene if it persists for multiple cron runs.
    if ($executorPresent && empty($rtorrentPids)) {
        $now = time();
        $firstSeen = 0;
        if (is_file($missingState)) {
            $firstSeen = (int) trim((string) @file_get_contents($missingState));
        }
        if ($firstSeen <= 0) {
            @file_put_contents($missingState, (string) $now, LOCK_EX);
            pmssCheckRtorrentLog("Executor present but rTorrent missing for {$user}; observing before restart", false, $debug);
            continue;
        }

        $age = $now - $firstSeen;
        if ($age < 180) {
            pmssCheckRtorrentLog(
                "Executor present but rTorrent missing for {$user}; waiting (age={$age}s)",
                false,
                $debug
            );
            continue;
        }

        pmssCheckRtorrentLog(
            "Executor present but rTorrent missing for {$user}; stale for {$age}s, restarting cleanly",
            true,
            $debug
        );
        if (function_exists('pmssUserLog')) {
            pmssUserLog($user, "checkRtorrent: executor present but rTorrent missing; stale for {$age}s; restarting");
        }

        $before = pmssCheckRtorrentPsSnapshot($user);
        pmssCheckRtorrentLog("Process snapshot BEFORE ({$user})", true, $debug);
        foreach ($before as $row) {
            pmssCheckRtorrentLog($row, true, $debug);
        }

        // Kill executor processes only; rTorrent is already missing.
        pmssCheckRtorrentKillPids($executorAllPids, SIGTERM);
        sleep(3);
        $executorAllPids = pmssCheckRtorrentExecutorPids($user)['all'];
        pmssCheckRtorrentKillPids($executorAllPids, SIGKILL);
        sleep(1);

        $rc = 0;
        @passthru('/scripts/startRtorrent '.escapeshellarg($user), $rc);
        pmssCheckRtorrentLog("startRtorrent {$user} completed (rc={$rc})", true, $debug);

        $after = pmssCheckRtorrentPsSnapshot($user);
        pmssCheckRtorrentLog("Process snapshot AFTER ({$user})", true, $debug);
        foreach ($after as $row) {
            pmssCheckRtorrentLog($row, true, $debug);
        }

        @unlink($missingState);
        continue;
    }

    // Check .rtorrent.rc ownership (legacy signal).
    if (file_exists($home.'/.rtorrent.rc') && function_exists('posix_getpwuid')) {
        $owner = @posix_getpwuid(@fileowner($home.'/.rtorrent.rc'));
        if (is_array($owner) && isset($owner['name']) && $owner['name'] !== 'root') {
            $changedConfig[] = $user.' -> '.$owner['name'];
        }
    }
}

if (count($changedConfig) !== 0) {
    file_put_contents('/root/changedConfigs', implode("\n", $changedConfig));
} elseif (file_exists('/root/changedConfigs')) {
    unlink('/root/changedConfigs');
}
