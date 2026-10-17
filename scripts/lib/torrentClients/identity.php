<?php
/** Process identity predicates for later launcher and stop integration. */

require_once dirname(__DIR__).'/user/watchdog.php';
require_once dirname(__DIR__).'/arrRootGuard.php';

/** Share the name, UID and mount context gate across both decisions. */
function pmssTorrentClientIdentityCandidates(string $user, string $client, array $ops): array
{
    $names = [
        'qbittorrent' => 'qbittorrent-nox', 'rtorrent' => 'rtorrent',
        'deluge' => 'deluged', 'transmission' => 'transmission-daemon',
    ];
    if (!isset($names[$client]) || !pmssValidateUsername($user)) return [];
    $account = isset($ops['account']) ? $ops['account']($user) : @posix_getpwnam($user);
    if (!is_array($account) || !isset($account['uid'])) return [];
    // Linux comm is capped at 15 bytes; full pgrep only enumerates candidates.
    $pids = isset($ops['pids']) ? $ops['pids']($user, $names[$client])
        : pmssUserWatchdogProcessPids($user, $names[$client], ['full' => true]);
    $read = $ops['read'] ?? static function (string $path) { return @file_get_contents($path); };
    $namespace = $ops['namespace'] ?? static function (int $pid) {
        return @readlink('/proc/'.($pid === 0 ? 'self' : $pid).'/ns/mnt');
    };
    $ownNs = $namespace(0);
    if (!is_string($ownNs) || $ownNs === '') return [];
    $matches = [];
    foreach ($pids as $pid) {
        if (!is_int($pid) || $pid < 1 || $namespace($pid) !== $ownNs) continue;
        $comm = $read('/proc/'.$pid.'/comm');
        $status = $read('/proc/'.$pid.'/status');
        if (!is_string($comm) || trim($comm) !== substr($names[$client], 0, 15)
            || !is_string($status) || preg_match('/^Uid:\s+(\d+)/m', $status, $uid) !== 1
            || (int) $uid[1] !== (int) $account['uid']) continue;
        $matches[] = $pid;
    }
    return array_values(array_unique($matches));
}

/** Liveness accepts user-built clients as well as managed store versions. */
function pmssTorrentClientLauncherLivenessPids(string $user, string $client, array $ops = []): array
{
    return pmssTorrentClientIdentityCandidates($user, $client, $ops);
}

/** Stop only a daemon whose executable belongs to this user's known roots. */
function pmssTorrentClientNativeStopPids(string $user, string $client, array $ops = []): array
{
    $guardNames = [
        'qbittorrent' => 'qBittorrent', 'rtorrent' => 'rTorrent',
        'deluge' => 'Deluge', 'transmission' => 'Transmission',
    ];
    $known = pmssRootGuardInstallPrefixes();
    if (!isset($guardNames[$client], $known[$guardNames[$client]])) return [];
    // Lexical account prefix: root must not realpath or stat tenant-owned bin.
    $roots = ['/home/.pmss/clients/'.$client.'/', '/home/'.$user.'/bin/'.$client.'/'];
    $exeRead = $ops['exe'] ?? static function (int $pid) { return @readlink('/proc/'.$pid.'/exe'); };
    $native = [];
    foreach (pmssTorrentClientIdentityCandidates($user, $client, $ops) as $pid) {
        $exe = $exeRead($pid);
        if (!is_string($exe) || $exe === '') continue;
        if (substr($exe, -10) === ' (deleted)') $exe = substr($exe, 0, -10);
        foreach ($roots as $root) {
            if (pmssRootGuardPathMatchesRoot($exe, $root)) {
                $native[] = $pid;
                break;
            }
        }
    }
    return $native;
}
