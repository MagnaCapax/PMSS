<?php
/** Customer-side resolver; it has no dependency on operator-only /scripts. */

/** Return a canonical executable only for a direct, allowed directory target. */
function pmssTorrentClientResolve(
    string $home,
    string $client,
    string $projectionPath = '/var/lib/pmss/public/torrentClients.json',
    string $storeRoot = '/home/.pmss/clients'
): ?string
{
    $names = [
        'qbittorrent' => 'qbittorrent-nox', 'rtorrent' => 'rtorrent',
        'deluge' => 'deluged', 'transmission' => 'transmission-daemon',
    ];
    if (!isset($names[$client]) || $home === '' || $home[0] !== '/'
        || strpos($home, "\0") !== false || strpos($home, '/..') !== false) return null;
    $base = rtrim($home, '/').'/bin/'.$client;
    $target = @readlink($base.'/default');
    if (!is_string($target) || $target === '') return null;
    $storePrefix = rtrim($storeRoot, '/').'/'.$client.'/';
    $ownPrefix = $base.'/';
    if ($target[0] !== '/') $target = $ownPrefix.$target;
    if (strpos($target, $storePrefix) === 0) {
        $version = substr($target, strlen($storePrefix));
        $store = true;
    } elseif (strpos($target, $ownPrefix) === 0) {
        $version = substr($target, strlen($ownPrefix));
        $store = false;
    } else return null;
    if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $version) !== 1
        || $version === 'default' || !is_dir($target) || is_link($target)) return null;
    if ($client === 'transmission' && !$store) return null;
    if ($store) {
        $stat = @lstat($projectionPath);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) return null;
        $raw = @file_get_contents($projectionPath);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $listed = false;
        foreach ((array) ($data['versions'] ?? []) as $entry) {
            if (($entry['client'] ?? null) === $client && ($entry['version'] ?? null) === $version
                && ($entry['kind'] ?? null) !== 'system'
                && ($client !== 'deluge' || ($entry['kind'] ?? null) === 'venv')
                && ($client !== 'transmission' || (($entry['kind'] ?? null) === 'static'
                    && ($entry['rpcUnixSocket'] ?? null) === true))) { $listed = true; break; }
        }
        if (!$listed) return null;
    }
    $binary = $target.($client === 'deluge' && $store ? '/bin/' : '/').$names[$client];
    return is_file($binary) && !is_link($binary) ? $binary : null;
}

/** Run both selected Deluge entrypoints from the same published venv. */
function pmssTorrentClientDelugeLaunch(
    string $home,
    string $projectionPath = '/var/lib/pmss/public/torrentClients.json',
    string $storeRoot = '/home/.pmss/clients',
    ?callable $run = null,
    string $component = 'both'
): bool
{
    if (!in_array($component, ['both', 'deluged', 'deluge-web'], true)) return false;
    if (!is_link($home.'/bin/deluge/default')) return false;
    $daemon = pmssTorrentClientResolve($home, 'deluge', $projectionPath, $storeRoot);
    if ($daemon === null || strpos($daemon, '/bin/deluged') !== strlen($daemon) - strlen('/bin/deluged')) return false;
    $web = dirname($daemon).'/deluge-web';
    if (!is_file($web) || is_link($web)) return false;
    $run = $run ?? static function (string $command): int {
        $rc = 1;
        @exec($command.' >/dev/null 2>&1', $unused, $rc);
        return $rc;
    };
    $rcDaemon = $component === 'deluge-web' ? 0
        : $run(escapeshellarg($daemon).' -l '.escapeshellarg($home.'/.delugeLog').' -L info');
    $rcWeb = $component === 'deluged' ? 0
        : $run(escapeshellarg($web).' -l '.escapeshellarg($home.'/.delugeWebLog').' -L info');
    return $rcDaemon === 0 && $rcWeb === 0;
}

/** Preserve the system rTorrent command unless a published store build is selected. */
function pmssRtorrentExecutorCommand(
    string $home,
    string $projectionPath = '/var/lib/pmss/public/torrentClients.json',
    string $storeRoot = '/home/.pmss/clients'
): string
{
    $binary = pmssTorrentClientResolve($home, 'rtorrent', $projectionPath, $storeRoot);
    $storePrefix = rtrim($storeRoot, '/').'/rtorrent/';
    if ($binary === null || strpos($binary, $storePrefix) !== 0) return '/usr/local/bin/rtorrent';
    return escapeshellarg($binary)
        .(pmssRtorrentDeprecatedRedirects(basename(dirname($binary)), $projectionPath) ? ' -D' : '');
}

/** Keep a failed store command on the system build until the resolved selection changes. */
function pmssRtorrentExecutorFallbackCommand(string $command, string $lastFailedCommand, int $fastExits): string
{
    return $command === $lastFailedCommand && $fastExits >= 3 ? '/usr/local/bin/rtorrent' : $command;
}

/** Count only consecutive short runs of the same store command; a long run clears the count. */
function pmssRtorrentExecutorFastExits(string $command, string $lastFailedCommand, int $fastExits, float $runtime): int
{
    if ($command === '/usr/local/bin/rtorrent' || $runtime >= 20) return 0;
    return $command === $lastFailedCommand ? $fastExits + 1 : 1;
}

/** Record the store build the executor gave up on, or forget it (null), so the panel can tell a fallback from a pending restart. */
function pmssRtorrentExecutorFallbackMark(string $home, ?string $failedCommand): void
{
    $path = rtrim($home, '/').'/.config/pmss/torrentClients/rtorrent.fallback';
    if ($failedCommand === null) {
        if (is_file($path) && !is_link($path)) @unlink($path);
        return;
    }
    if (preg_match('#/rtorrent/([A-Za-z0-9][A-Za-z0-9._-]{0,63})/rtorrent\b#', $failedCommand, $m) !== 1) return;
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && !is_link($dir) && !is_link($path)) @file_put_contents($path, $m[1]."\n");
}

/** The store build the executor last fell back from, or '' when none is recorded. */
function pmssRtorrentExecutorFallbackVersion(string $home): string
{
    $path = rtrim($home, '/').'/.config/pmss/torrentClients/rtorrent.fallback';
    if (!is_file($path) || is_link($path) || @filesize($path) > 128) return '';
    return trim((string) @file_get_contents($path));
}

/** rTorrent 0.16 renamed rc and RPC commands; -D keeps the old names the template and ruTorrent use. */
function pmssRtorrentDeprecatedRedirects(string $version, string $projectionPath): bool
{
    $raw = @file_get_contents($projectionPath);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    foreach ((array) ($data['versions'] ?? []) as $entry) {
        if (($entry['client'] ?? null) !== 'rtorrent' || ($entry['version'] ?? null) !== $version) continue;
        $family = (string) ($entry['compatFamily'] ?? '');
        return preg_match('/\A[0-9]+(\.[0-9]+)*\z/', $family) === 1 && version_compare($family, '0.16', '>=');
    }
    return false;
}

/** Start a selected client as the account while excluding a concurrent switch. */
function pmssTorrentClientResolverLaunch(string $home, string $client, bool $lockHeld = false): bool
{
    if ($client !== 'qbittorrent' || !is_link($home.'/bin/'.$client.'/default')) return false;
    $lock = null;
    if (!$lockHeld) {
        $dir = $home.'/.config/pmss/torrentClients';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
        if (is_link($dir)) return false;
        $lock = @fopen($dir.'/'.$client.'.lock', 'ce');
        if (!is_resource($lock)) return false;
        if (!@flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return true; }
    }
    $binary = pmssTorrentClientResolve($home, $client);
    $rc = 1;
    if ($binary !== null) @exec(escapeshellarg($binary).' -d >/dev/null 2>&1', $unused, $rc);
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    return $rc === 0;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $home = getenv('HOME');
    if (is_string($home) && ($argv[1] ?? '') === 'transmission' && ($argv[2] ?? '') === '--launch') {
        require_once __DIR__.'/transmissionLaunch.php';
        // The daemon rewrites settings.json when it stops, so re-assert the managed RPC settings first,
        // as the Configure start path does. Unchanged settings are not rewritten.
        exit(pmssTransmissionSettingsPrepare($home) && pmssTransmissionLaunch($home) ? 0 : 1);
    }
    if (is_string($home) && ($argv[1] ?? '') === 'deluge' && ($argv[2] ?? '') === '--launch') {
        exit(pmssTorrentClientDelugeLaunch($home, '/var/lib/pmss/public/torrentClients.json',
            '/home/.pmss/clients', null, $argv[3] ?? 'both') ? 0 : 1);
    }
    exit(is_string($home) && ($argv[1] ?? '') === 'qbittorrent' && ($argv[2] ?? '') === '--launch'
        && pmssTorrentClientResolverLaunch($home, 'qbittorrent', ($argv[3] ?? '') === '--held') ? 0 : 1);
}
