<?php
/**
 * Kill any Servarr (*ARR) application running as root.
 *
 * The structural defence is scripts/lib/update/arrRootExecutionBlock.php, which makes a root
 * launch abort before it binds. This is the backstop for the cases that defence cannot reach: an
 * instance started before the block existed, or one started with an explicit data directory
 * somewhere else. A root-owned process executing an *ARR install is never legitimate --
 * scripts/lib/update/apps/arr.php installs these applications and must never run them.
 *
 * Selection is exe-path + real uid 0, deliberately NOT a cmdline/pkill match: customers run their
 * OWN *ARR from /home/<user>/.bin/<App> via dotnet, so a cmdline match would kill paying
 * customers' media stacks. uid 0 alone already excludes every customer instance; the exe path is
 * the second axis. Neither can match this scanner: it runs as PHP and its own command line does
 * not contain an install path.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/update/apps/arr.php';

/** Install prefixes PMSS uses for the shared *ARR installs. */
function pmssArrRootGuardInstallPrefixes(string $installRoot = '/opt'): array
{
    $prefixes = array();
    foreach (array_keys(PMSS_ARR_APP_BRANCHES) as $app) {
        $prefixes[$app] = $installRoot.'/'.$app.'/';
    }

    return $prefixes;
}

/**
 * Name the *ARR application an executable path belongs to, or null when it belongs to none.
 *
 * The trailing slash on each prefix anchors the match, so /opt/RadarrEvil and /opt/Radarr2 do not
 * resolve to Radarr.
 */
function pmssArrRootGuardAppForExe(string $exe, array $prefixes): ?string
{
    foreach ($prefixes as $app => $prefix) {
        if (strncmp($exe, $prefix, strlen($prefix)) === 0) {
            return $app;
        }
    }

    return null;
}

/** Real uid of a /proc entry, or null when it cannot be read. */
function pmssArrRootGuardProcessUid(string $procDir): ?int
{
    $status = @file_get_contents($procDir.'/status');
    if (!is_string($status) || preg_match('/^Uid:\s+(\d+)/m', $status, $match) !== 1) {
        return null;
    }

    return (int) $match[1];
}

/**
 * List root-owned *ARR processes as pid => array{app,exe}.
 *
 * A process that exits mid-scan simply drops out: every read is failure-tolerant.
 */
function pmssArrRootGuardScan(string $procRoot = '/proc', string $installRoot = '/opt'): array
{
    $prefixes = pmssArrRootGuardInstallPrefixes($installRoot);
    $found = array();

    foreach ((array) glob($procRoot.'/[0-9]*', GLOB_ONLYDIR) as $procDir) {
        $exe = @readlink($procDir.'/exe');
        if (!is_string($exe) || $exe === '') {
            continue;
        }
        $app = pmssArrRootGuardAppForExe($exe, $prefixes);
        if ($app === null || pmssArrRootGuardProcessUid($procDir) !== 0) {
            continue;
        }

        $found[(int) basename($procDir)] = array('app' => $app, 'exe' => $exe);
    }

    return $found;
}

/**
 * Signal one process, preferring its process group.
 *
 * Group signalling matters because these applications daemonize: killing only the direct pid can
 * leave helper children behind (ADR 0035). The gate is that the pid actually LEADS its group --
 * otherwise a negative pid would signal a group we do not own.
 */
function pmssArrRootGuardSignal(int $pid, int $signal): bool
{
    if (function_exists('posix_getpgid') && @posix_getpgid($pid) === $pid) {
        return (bool) @posix_kill(-$pid, $signal);
    }

    return (bool) @posix_kill($pid, $signal);
}

/**
 * Kill every root-owned *ARR process; returns how many were signalled.
 *
 * Silent when there is nothing to kill -- the log stays a signal rather than a heartbeat. Every
 * kill is also recorded in the server's own audit log, because this destroys state that somebody
 * may later ask about.
 */
function pmssArrRootGuardKillAll(callable $log, string $procRoot = '/proc', string $installRoot = '/opt'): int
{
    $killed = 0;
    foreach (pmssArrRootGuardScan($procRoot, $installRoot) as $pid => $process) {
        if (!pmssArrRootGuardSignal($pid, defined('SIGKILL') ? SIGKILL : 9)) {
            $log('[WARN] arr root guard: unable to signal pid '.$pid.' ('.$process['exe'].')');
            continue;
        }

        $killed++;
        $message = 'arr root guard: killed root-owned '.$process['app'].' pid '.$pid.' ('.$process['exe'].')';
        $log($message);
        @file_put_contents('/root/sysadmin.agentic.log', gmdate('c').' PMSS '.$message."\n", FILE_APPEND);
    }

    return $killed;
}
