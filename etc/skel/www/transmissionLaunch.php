<?php
/** Customer-owned Transmission launcher and socket checks. */
require_once __DIR__.'/torrentClientsResolve.php';

/** A connection probe does not send an RPC request. */
function pmssTransmissionSocketLive(string $socketPath): bool
{
    $stream = @stream_socket_client('unix://'.$socketPath, $errno, $error, 0.05);
    if (!is_resource($stream)) return false;
    fclose($stream);
    return true;
}

/** Match the selected executable in this account and mount namespace. */
function pmssTransmissionNativePids(string $binary, string $procRoot = '/proc'): array
{
    $ownNs = @readlink('/proc/self/ns/mnt');
    if (!is_string($ownNs) || $ownNs === '') return [];
    $uid = posix_geteuid();
    $pids = [];
    foreach ((array) glob(rtrim($procRoot, '/').'/[0-9]*', GLOB_ONLYDIR) as $dir) {
        $pid = (int) basename($dir);
        if ($pid < 1 || @readlink($dir.'/ns/mnt') !== $ownNs
            || trim((string) @file_get_contents($dir.'/comm')) !== 'transmission-da') continue;
        $status = @file_get_contents($dir.'/status');
        if (!is_string($status) || preg_match('/^Uid:\s+(\d+)/m', $status, $match) !== 1
            || (int) $match[1] !== $uid) continue;
        $exe = @readlink($dir.'/exe');
        if (is_string($exe) && preg_replace('/ \(deleted\)$/', '', $exe) === $binary) $pids[] = $pid;
    }
    return $pids;
}

/** Require the stopped-daemon settings before a selected build may start. */
function pmssTransmissionSettingsReady(string $home): bool
{
    $path = $home.'/.config/transmission-daemon/settings.json';
    $entry = @lstat($path);
    if (!is_array($entry) || ($entry['mode'] & 0170000) !== 0100000 || $entry['size'] > 65536) return false;
    $raw = @file_get_contents($path);
    $settings = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($settings)) return false;
    $managed = [
        'rpc-enabled' => true,
        'rpc-bind-address' => 'unix:'.$home.'/.lighttpd/transmission.sock',
        'rpc-socket-mode' => '0700',
        'rpc-authentication-required' => false,
        'rpc-whitelist-enabled' => true,
        'rpc-whitelist' => '',
        'rpc-host-whitelist-enabled' => false,
        'rpc-url' => '/user-'.basename($home).'/transmission/',
    ];
    foreach ($managed as $kebab => $value) {
        $snake = str_replace('-', '_', $kebab);
        if (array_key_exists($kebab, $settings) === array_key_exists($snake, $settings)) return false;
        if (($settings[array_key_exists($snake, $settings) ? $snake : $kebab] ?? null) !== $value) return false;
    }
    return true;
}

/** Keep flock as the foreground daemon's parent for its whole lifetime. */
function pmssTransmissionLaunch(string $home, array $ops = []): bool
{
    $binary = pmssTorrentClientResolve($home, 'transmission',
        $ops['projection'] ?? '/var/lib/pmss/public/torrentClients.json',
        $ops['storeRoot'] ?? '/home/.pmss/clients');
    $dir = $home.'/.config/transmission-daemon';
    $socket = $home.'/.lighttpd/transmission.sock';
    if ($binary === null || !is_dir($dir) || is_link($dir) || !pmssTransmissionSettingsReady($home)) return false;
    $native = $ops['native'] ?? 'pmssTransmissionNativePids';
    $probe = $ops['probe'] ?? 'pmssTransmissionSocketLive';
    $pids = $native($binary);
    $live = $probe($socket);
    if ($pids !== [] || $live) return $pids !== [] && $live;
    $lock = $dir.'/.pmss-launch.lock';
    if (is_link($lock) || (file_exists($lock) && !is_file($lock))) return false;
    $command = 'nohup flock -n '.escapeshellarg($lock).' env '
        .'CURL_CA_BUNDLE=/etc/ssl/certs/ca-certificates.crt '.escapeshellarg($binary)
        .' -f -g '.escapeshellarg($dir).' >/dev/null 2>&1 </dev/null &';
    $run = $ops['run'] ?? static function (string $shell): void { @exec($shell); };
    $run($command);
    for ($attempt = 0; $attempt < 40; $attempt++) {
        if ($probe($socket)) return true;
        usleep(50000);
    }
    return false;
}
