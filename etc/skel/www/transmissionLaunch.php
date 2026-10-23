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

/** One customer-side contract for both preparing and checking socket settings. */
function pmssTransmissionManagedSettings(string $home): array
{
    return array(
        'rpc-enabled' => true,
        'rpc-bind-address' => 'unix:'.$home.'/.lighttpd/transmission.sock',
        'rpc-socket-mode' => '0700',
        'rpc-authentication-required' => false,
        'rpc-whitelist-enabled' => true,
        'rpc-whitelist' => '',
        'rpc-host-whitelist-enabled' => false,
        'rpc-url' => '/user-'.basename($home).'/transmission/',
    );
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
    foreach (pmssTransmissionManagedSettings($home) as $kebab => $value) {
        $snake = str_replace('-', '_', $kebab);
        if (array_key_exists($kebab, $settings) === array_key_exists($snake, $settings)) return false;
        if (($settings[array_key_exists($snake, $settings) ? $snake : $kebab] ?? null) !== $value) return false;
    }
    return true;
}

/** Prepare the same socket-only settings on the first account-side selection. */
function pmssTransmissionSettingsPrepare(string $home): bool
{
    $parent = $home.'/.config';
    $dir = $parent.'/transmission-daemon';
    $socketDir = $home.'/.lighttpd';
    if (!is_dir($parent) || is_link($parent) || !is_dir($socketDir) || is_link($socketDir)
        || is_link($socketDir.'/transmission.sock')
        || ((!is_dir($dir) && !@mkdir($dir, 0700)) || is_link($dir))) return false;
    $path = $dir.'/settings.json';
    $size = is_file($path) ? @filesize($path) : 0;
    if (is_link($path) || (file_exists($path) && (!is_file($path) || !is_int($size) || $size > 65536))) return false;
    $raw = is_file($path) ? @file_get_contents($path) : '{}';
    $settings = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($settings) || ($settings !== array() && array_values($settings) === $settings)) return false;
    $snakeCase = array_key_exists('rpc_bind_address', $settings);
    foreach (pmssTransmissionManagedSettings($home) as $key => $value) {
        $snake = str_replace('-', '_', $key);
        unset($settings[$key], $settings[$snake]);
        $settings[$snakeCase ? $snake : $key] = $value;
    }
    foreach (array('rpc-username', 'rpc-password') as $key) {
        unset($settings[$key], $settings[str_replace('-', '_', $key)]);
    }
    $content = json_encode((object) $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return is_string($content) && (is_string($raw) && $content."\n" === $raw
        && (@fileperms($path) & 0777) === 0600
        || pmssConfigureFileWrite($path, $content."\n")) && pmssTransmissionSettingsReady($home);
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
    // Static store builds keep their matching web client beside the daemon.
    $web = dirname($binary).'/public_html';
    $command = 'nohup flock -n '.escapeshellarg($lock).' env '
        .'CURL_CA_BUNDLE=/etc/ssl/certs/ca-certificates.crt '
        .(is_dir($web) && !is_link($web) ? escapeshellarg('TRANSMISSION_WEB_HOME='.$web).' ' : '')
        .escapeshellarg($binary)
        .' -f -g '.escapeshellarg($dir).' >/dev/null 2>&1 </dev/null &';
    $run = $ops['run'] ?? static function (string $shell): void { @exec($shell); };
    $run($command);
    for ($attempt = 0; $attempt < 40; $attempt++) {
        if ($probe($socket)) return true;
        usleep(50000);
    }
    return false;
}
