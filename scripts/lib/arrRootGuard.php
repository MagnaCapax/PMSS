<?php
/**
 * Detect consumer applications running as root and kill only known install paths.
 *
 * The structural defence is scripts/lib/update/arrRootExecutionBlock.php, which makes a root
 * launch abort before it binds. This is the backstop for the cases that defence cannot reach: an
 * instance started before the block existed, or one started with an explicit data directory
 * somewhere else. A root-owned process executing a managed consumer install is never
 * legitimate; scripts/lib/update/apps/arr.php installs the ARR subset and must never run it.
 *
 * Selection is exe-path + (real uid 0 or the app's default listener), deliberately NOT a
 * cmdline/pkill match: customers run their OWN *ARR from /home/<user>/.bin/<App> via dotnet, so a
 * cmdline match would kill paying customers' media stacks. The install path is the first axis;
 * uid or socket ownership is the second. Neither can match this scanner: it runs as PHP and its
 * own command line does not contain an install path.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/update/apps/arr.php';

/** Known system installation paths for consumer applications that must not run as root. */
const PMSS_ROOT_GUARD_APP_INSTALL_ROOTS = array(
    'Lidarr' => array('/opt/Lidarr/'),
    'Prowlarr' => array('/opt/Prowlarr/'),
    'Radarr' => array('/opt/Radarr/'),
    'Readarr' => array('/opt/Readarr/'),
    'Sonarr' => array('/opt/Sonarr/'),
    'Whisparr' => array('/opt/Whisparr/'),
    'qBittorrent' => array('/opt/qBittorrent/', '/usr/bin/qbittorrent-nox', '/usr/local/bin/qbittorrent-nox'),
    'Deluge' => array('/opt/Deluge/', '/usr/bin/deluged', '/usr/bin/deluge-web', '/usr/local/bin/deluged', '/usr/local/bin/deluge-web'),
    'Transmission' => array('/opt/Transmission/', '/usr/bin/transmission-daemon', '/usr/local/bin/transmission-daemon'),
    'Jellyfin' => array('/opt/Jellyfin/', '/usr/lib/jellyfin/'),
    'SABnzbd' => array('/opt/SABnzbd/', '/usr/bin/sabnzbdplus', '/usr/local/bin/sabnzbdplus'),
    'rTorrent' => array('/opt/rTorrent/', '/usr/local/bin/rtorrent', '/usr/bin/rtorrent'),
    'Syncthing' => array('/opt/Syncthing/', '/usr/bin/syncthing', '/usr/local/bin/syncthing'),
);

/** Default listeners for the supported Servarr applications. */
const PMSS_ROOT_GUARD_ARR_DEFAULT_PORTS = array(
    'Lidarr' => 8686,
    'Prowlarr' => 9696,
    'Radarr' => 7878,
    'Readarr' => 8787,
    'Sonarr' => 8989,
);

/** Paths treated as standard system locations for the alert-only unknown-process predicate. */
const PMSS_ROOT_GUARD_STANDARD_PATHS = array(
    '/usr/bin',
    '/usr/sbin',
    '/bin',
    '/sbin',
    '/usr/lib',
    '/usr/libexec',
    '/usr/local',
    '/opt',
);

/** Install prefixes PMSS uses for the shared *ARR installs. */
function pmssArrRootGuardInstallPrefixes(string $installRoot = '/opt'): array
{
    $prefixes = array();
    foreach (array_keys(PMSS_ARR_APP_BRANCHES) as $app) {
        $prefixes[$app] = $installRoot.'/'.$app.'/';
    }

    return $prefixes;
}

/** Resolve every known consumer application path, allowing ARR tests to use a temp install root. */
function pmssRootGuardInstallPrefixes(string $installRoot = '/opt'): array
{
    $installRoot = rtrim($installRoot, '/');
    $prefixes = array();
    foreach (PMSS_ROOT_GUARD_APP_INSTALL_ROOTS as $app => $roots) {
        $prefixes[$app] = array();
        foreach ($roots as $root) {
            $prefixes[$app][] = strpos($root, '/opt/') === 0
                ? $installRoot.substr($root, 4)
                : $root;
        }
    }

    return $prefixes;
}

/** Return whether an executable path is exactly a known binary or lies under a known root. */
function pmssRootGuardPathMatchesRoot(string $exe, string $root): bool
{
    $root = rtrim($root, '/');
    return $exe === $root || strpos($exe, $root.'/') === 0;
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

/** Name any known consumer application for an executable path, or null when it is unknown. */
function pmssRootGuardAppForExe(string $exe, array $prefixes): ?string
{
    foreach ($prefixes as $app => $roots) {
        foreach ((array) $roots as $root) {
            if (pmssRootGuardPathMatchesRoot($exe, (string) $root)) {
                return (string) $app;
            }
        }
    }

    return null;
}

/** Return true when an executable path belongs to an allowed standard system location. */
function pmssRootGuardExeIsStandardPath(string $exe): bool
{
    foreach (PMSS_ROOT_GUARD_STANDARD_PATHS as $root) {
        if (pmssRootGuardPathMatchesRoot($exe, $root)) {
            return true;
        }
    }

    return false;
}

/** Real uid of a /proc entry, or null when it cannot be read. */
function pmssRootGuardProcessUid(string $procDir): ?int
{
    $status = @file_get_contents($procDir.'/status');
    if (!is_string($status) || preg_match('/^Uid:\s+(\d+)/m', $status, $match) !== 1) {
        return null;
    }

    return (int) $match[1];
}

/** Map listening TCP ports to their kernel socket inodes. */
function pmssRootGuardListeningSocketInodes(string $procNetRoot): array
{
    $inodes = array();
    foreach (array('tcp', 'tcp6') as $table) {
        $lines = @file($procNetRoot.'/'.$table, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ((array) $lines as $line) {
            $fields = preg_split('/\s+/', trim((string) $line));
            if (!is_array($fields) || count($fields) < 10 || ($fields[3] ?? '') !== '0A') {
                continue;
            }

            $local = explode(':', (string) ($fields[1] ?? ''));
            if (count($local) !== 2 || !ctype_xdigit($local[1]) || !ctype_digit((string) $fields[9])) {
                continue;
            }

            $inodes[hexdec($local[1])][] = (string) $fields[9];
        }
    }

    return $inodes;
}

/** Return true when a process owns a listening socket on the selected port. */
function pmssRootGuardProcessHasListeningPort(string $procDir, int $port, array $socketInodes): bool
{
    $wanted = array_fill_keys($socketInodes[$port] ?? array(), true);
    if ($wanted === array()) {
        return false;
    }

    foreach ((array) glob($procDir.'/fd/*') as $fd) {
        $target = @readlink($fd);
        if (is_string($target) && preg_match('/^socket:\[(\d+)\]$/', $target, $match) === 1
            && isset($wanted[$match[1]])) {
            return true;
        }
    }

    return false;
}

/**
 * List root-owned known consumer processes and unknown non-standard executables.
 *
 * A process that exits mid-scan simply drops out: every read is failure-tolerant.
 */
function pmssRootGuardScan(
    string $procRoot = '/proc',
    string $installRoot = '/opt',
    string $procNetRoot = '/proc/net'
): array
{
    $prefixes = pmssRootGuardInstallPrefixes($installRoot);
    $socketInodes = pmssRootGuardListeningSocketInodes($procNetRoot);
    $found = array();

    foreach ((array) glob($procRoot.'/[0-9]*', GLOB_ONLYDIR) as $procDir) {
        $exe = @readlink($procDir.'/exe');
        if (!is_string($exe) || $exe === '') {
            continue;
        }
        $uid = pmssRootGuardProcessUid($procDir);
        if ($uid === null) {
            continue;
        }

        $app = pmssRootGuardAppForExe($exe, $prefixes);
        $uidMatch = $uid === 0;
        $defaultPort = $app !== null ? (PMSS_ROOT_GUARD_ARR_DEFAULT_PORTS[$app] ?? null) : null;
        $portMatch = $app !== null && $defaultPort !== null
            && pmssRootGuardProcessHasListeningPort($procDir, $defaultPort, $socketInodes);

        if ($app === null && (!$uidMatch || pmssRootGuardExeIsStandardPath($exe))) {
            continue;
        }
        if ($app !== null && !$uidMatch && !$portMatch) {
            continue;
        }

        $predicate = $uidMatch ? 'uid0' : '';
        if ($portMatch) {
            $predicate .= ($predicate === '' ? '' : ',').'default_port';
        }

        $found[(int) basename($procDir)] = array(
            'app' => $app,
            'uid' => $uid,
            'exe' => $exe,
            'action' => $app === null ? 'alert' : 'kill',
            'predicate' => $predicate,
        );
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
function pmssRootGuardSignal(int $pid, int $signal): bool
{
    if (function_exists('posix_getpgid') && @posix_getpgid($pid) === $pid) {
        return (bool) @posix_kill(-$pid, $signal);
    }

    return (bool) @posix_kill($pid, $signal);
}

/**
 * Kill every selected *ARR process; returns how many were signalled.
 *
 * Silent when there is nothing to kill -- the log stays a signal rather than a heartbeat. The
 * caller's logger is the single sink; the cron entry already routes it to a persistent file, so a
 * second hand-rolled append here would only duplicate it.
 */
function pmssRootGuardKillAll(
    callable $log,
    string $procRoot = '/proc',
    string $installRoot = '/opt',
    string $procNetRoot = '/proc/net'
): int
{
    $killed = 0;
    foreach (pmssRootGuardScan($procRoot, $installRoot, $procNetRoot) as $pid => $process) {
        if (($process['action'] ?? '') !== 'kill') {
            continue;
        }
        if (!pmssRootGuardSignal($pid, defined('SIGKILL') ? SIGKILL : 9)) {
            $log('###PMSS_ROOT_GUARD_ALERT action=kill_failed pid='.$pid.' uid='.$process['uid'].' app='.$process['app'].' exe='.$process['exe'].' predicate='.$process['predicate']);
            continue;
        }

        $killed++;
        $log('###PMSS_ROOT_GUARD_ALERT action=killed pid='.$pid.' uid='.$process['uid'].' app='.$process['app'].' exe='.$process['exe'].' predicate='.$process['predicate']);
    }

    return $killed;
}

/** Alert on every finding and kill only applications with a known install path. */
function pmssRootGuardAuditAndKill(
    callable $log,
    string $procRoot = '/proc',
    string $installRoot = '/opt',
    ?callable $signal = null,
    string $procNetRoot = '/proc/net'
): int {
    $findings = 0;
    foreach (pmssRootGuardScan($procRoot, $installRoot, $procNetRoot) as $pid => $process) {
        $findings++;
        if (($process['action'] ?? '') !== 'kill') {
            $log('###PMSS_ROOT_GUARD_ALERT action=observed_unknown_root_process pid='.$pid.' uid='.$process['uid'].' exe='.$process['exe'].' predicate='.$process['predicate']);
            continue;
        }

        $signalled = $signal !== null
            ? (bool) $signal($pid, defined('SIGKILL') ? SIGKILL : 9)
            : pmssRootGuardSignal($pid, defined('SIGKILL') ? SIGKILL : 9);
        $action = $signalled ? 'killed' : 'kill_failed';
        $log('###PMSS_ROOT_GUARD_ALERT action='.$action.' pid='.$pid.' uid='.$process['uid'].' app='.$process['app'].' exe='.$process['exe'].' predicate='.$process['predicate']);
    }

    return $findings;
}
