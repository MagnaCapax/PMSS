<?php
/** Process identity predicates for later launcher and stop integration. */

require_once dirname(__DIR__).'/user/watchdog.php';
require_once dirname(__DIR__).'/arrRootGuard.php';
require_once __DIR__.'/storePaths.php';

/** Share the name, UID and mount context gate across both decisions. */
function pmssTorrentClientIdentityCandidates(string $user, string $client, array $ops): array
{
    $names = pmssTorrentClientsBinaryNames();
    if (!isset($names[$client]) || !pmssValidateUsername($user)) return [];
    $processName = $client === 'deluge' && ($ops['processName'] ?? '') === 'deluge-web'
        ? 'deluge-web' : $names[$client];
    $account = isset($ops['account']) ? $ops['account']($user) : @posix_getpwnam($user);
    if (!is_array($account) || !isset($account['uid'])) return [];
    // Linux comm is capped at 15 bytes; full pgrep only enumerates candidates.
    $pids = isset($ops['pids']) ? $ops['pids']($user, $processName)
        : pmssUserWatchdogProcessPids($user, $processName, ['full' => true]);
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
        $script = $client === 'deluge' ? pmssTorrentClientDelugeScriptPath($pid, $processName, $read) : '';
        if (!is_string($comm) || (trim($comm) !== substr($processName, 0, 15)
                && !($script !== '' && preg_match('/\Apython(?:3(?:\.[0-9]+)?)?\z/', trim($comm)) === 1))
            || !is_string($status) || preg_match('/^Uid:\s+(\d+)/m', $status, $uid) !== 1
            || (int) $uid[1] !== (int) $account['uid']) continue;
        $matches[] = $pid;
    }
    return array_values(array_unique($matches));
}

/** Read the interpreter's argv[1] without resolving an account-owned path. */
function pmssTorrentClientDelugeScriptPath(int $pid, string $processName, callable $read): string
{
    $raw = $read('/proc/'.$pid.'/cmdline');
    if (!is_string($raw)) return '';
    $args = explode("\0", $raw);
    $script = $args[1] ?? '';
    return is_string($script) && $script !== '' && $script[0] === '/'
        && substr($script, -strlen('/'.$processName)) === '/'.$processName ? $script : '';
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
    $installRoots = $ops['installRoots'] ?? $known[$guardNames[$client]];
    // Lexical account prefixes: root must not realpath or stat tenant-owned bin.
    $accountRoots = $ops['accountRoots'] ??
        ['/home/.pmss/clients/'.$client.'/', '/home/'.$user.'/bin/'.$client.'/'];
    // Customers also launch qBittorrent directly from ~/bin before choosing a managed version.
    if ($client === 'qbittorrent' && !isset($ops['accountRoots'])) $accountRoots[] = '/home/'.$user.'/bin/qbittorrent-nox';
    if ($client === 'deluge' && !empty($ops['storeOnly'])) {
        $accountRoots = array_values(array_filter($accountRoots, static function (string $root): bool {
            return strpos($root, '/.pmss/clients/deluge/') !== false;
        }));
    }
    $nativeInstallRoots = $installRoots;
    foreach ($installRoots as $root) {
        $resolved = @realpath($root);
        if ($resolved !== false) $nativeInstallRoots[] = $resolved;
    }
    $roots = $client === 'deluge' && !empty($ops['storeOnly'])
        ? $accountRoots : array_merge($nativeInstallRoots, $accountRoots);
    $exeRead = $ops['exe'] ?? static function (int $pid) { return @readlink('/proc/'.$pid.'/exe'); };
    $read = $ops['read'] ?? static function (string $path) { return @file_get_contents($path); };
    $processName = $client === 'deluge' && ($ops['processName'] ?? '') === 'deluge-web' ? 'deluge-web' : 'deluged';
    $native = [];
    foreach (pmssTorrentClientIdentityCandidates($user, $client, $ops) as $pid) {
        $exe = $exeRead($pid);
        if (!is_string($exe) || $exe === '') continue;
        if (substr($exe, -10) === ' (deleted)') $exe = substr($exe, 0, -10);
        if ($client === 'deluge' && preg_match('/\Apython(?:3(?:\.[0-9]+)?)?\z/', basename($exe)) === 1) {
            $exe = pmssTorrentClientDelugeScriptPath($pid, $processName, $read);
            if ($exe === '' || (isset($ops['scriptPath']) && $exe !== $ops['scriptPath'])) continue;
        }
        // The same roots admit system scripts; storeOnly retains store roots only.
        foreach ($roots as $root) {
            if (pmssRootGuardPathMatchesRoot($exe, $root)) {
                $native[] = $pid;
                break;
            }
        }
    }
    return $native;
}

/** Recheck the whole identity immediately before signalling a specific PID. */
function pmssTorrentClientNativeStop(string $user, string $client, int $signal = 15, array $ops = []): int
{
    $signal = $signal === 9 ? 9 : 15;
    $signalPid = $ops['signal'] ?? static function (int $pid, int $value): bool {
        return @posix_kill($pid, $value);
    };
    $stopped = 0;
    foreach (pmssTorrentClientNativeStopPids($user, $client, $ops) as $pid) {
        // Enumeration results are hints: a reused PID must pass the fresh predicate.
        if (!in_array($pid, pmssTorrentClientNativeStopPids($user, $client, $ops), true)) continue;
        if ($signalPid($pid, $signal)) $stopped++;
    }
    return $stopped;
}

/**
 * SIGTERM the account's native client, wait up to about ten seconds for it to exit, then SIGKILL any left.
 * A client killed mid-write loses its in-flight data, and one that is still exiting can rewrite its config
 * after the caller edits it, so callers that edit config next use this instead of a bare SIGKILL.
 *
 * @param array{stop?:callable,remaining?:callable,pause?:callable,attempts?:int} $ops
 */
function pmssTorrentClientNativeStopWithGrace(string $user, string $client, array $ops = []): void
{
    $stop = $ops['stop'] ?? static function (int $signal) use ($user, $client): int { return pmssTorrentClientNativeStop($user, $client, $signal); };
    $remaining = $ops['remaining'] ?? static function () use ($user, $client): array { return pmssTorrentClientNativeStopPids($user, $client); };
    $pause = $ops['pause'] ?? static function (): void { usleep(200000); };
    $attempts = $ops['attempts'] ?? 50;
    $stop(15);
    for ($i = 0; $i < $attempts && $remaining() !== []; $i++) $pause();
    if ($remaining() !== []) $stop(9);
}

/**
 * Stop only the account's deluge-web with the SIGTERM grace sequence; deluged is never signalled.
 *
 * @param array $ops identity seams plus the optional grace seams of pmssTorrentClientNativeStopWithGrace
 */
function pmssDelugeWebStopWithGrace(string $user, array $ops = []): void
{
    $ops['processName'] = 'deluge-web';
    pmssTorrentClientNativeStopWithGrace($user, 'deluge', $ops + [
        'stop' => static function (int $signal) use ($user, $ops): int { return pmssTorrentClientNativeStop($user, 'deluge', $signal, $ops); },
        'remaining' => static function () use ($user, $ops): array { return pmssTorrentClientNativeStopPids($user, 'deluge', $ops); },
    ]);
}
