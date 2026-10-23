<?php
/** Managed Transmission settings are written before the selected daemon starts. */

require_once __DIR__.'/../torrentClients/identity.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';

/** Opt-out marker test for root: lstat only, so a planted link is never followed and any link counts as disabled. */
function pmssTransmissionAccountEnabled(string $home): bool
{
    $marker = $home.'/.transmissionDisable';
    return !(is_link($marker) || is_file($marker));
}

/** Reject tenant-controlled links before the root cron probes a socket. */
function pmssTransmissionSocketPathIsSymlink(string $socketPath): bool
{
    return is_link(dirname($socketPath)) || is_link($socketPath);
}

/** Preserve unrelated keys while keeping one spelling for each managed setting. */
function pmssTransmissionSettingsContent(string $raw, string $socketPath, string $rpcPath, ?bool $snakeCase = null): ?string
{
    $settings = json_decode($raw, true);
    if (!is_array($settings) || ($settings !== [] && array_values($settings) === $settings)) return null;
    if ($snakeCase === null) {
        $snakeCase = array_key_exists('rpc_bind_address', $settings);
    }
    $managed = [
        'rpc-enabled' => true,
        'rpc-bind-address' => 'unix:'.$socketPath,
        'rpc-socket-mode' => '0700',
        'rpc-authentication-required' => false,
        'rpc-whitelist-enabled' => true,
        'rpc-whitelist' => '',
        'rpc-host-whitelist-enabled' => false,
        'rpc-url' => $rpcPath,
    ];
    foreach ($managed as $kebab => $value) {
        $snake = str_replace('-', '_', $kebab);
        unset($settings[$kebab], $settings[$snake]);
        $settings[$snakeCase ? $snake : $kebab] = $value;
    }
    foreach (['rpc-username', 'rpc-password'] as $unused) {
        unset($settings[$unused], $settings[str_replace('-', '_', $unused)]);
    }
    $encoded = json_encode((object) $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded."\n" : null;
}

/** Apply only when no same-account Transmission process is present. */
function pmssTransmissionSettingsApply(string $user, string $home, array $ops = []): bool
{
    if (!pmssValidateUsername($user) || $home === '' || $home[0] !== '/'
        || strpos($home, "\0") !== false || basename($home) !== $user) return false;
    $running = $ops['running'] ?? static function (string $name): bool {
        return pmssTorrentClientLauncherLivenessPids($name, 'transmission') !== [];
    };
    $socketPath = $home.'/.lighttpd/transmission.sock';
    $socketLive = $ops['socketLive'] ?? static function (string $path): bool {
        $stream = @stream_socket_client('unix://'.$path, $errno, $error, 0.05);
        if (!is_resource($stream)) return false;
        fclose($stream);
        return true;
    };
    if ($running($user) || $socketLive($socketPath)) return false;
    $dir = $home.'/.config/transmission-daemon';
    $path = $dir.'/settings.json';
    $prepare = $ops['prepare'] ?? static function (string $name, string $accountHome, string $configDir): bool {
        $parent = dirname($configDir);
        if (!pmssAccountPathRun($name, $accountHome, [$parent],
            'mkdir -m 700 -p '.escapeshellarg($parent))) return false;
        return !is_link($parent) && pmssAccountPathRun($name, $accountHome, [$configDir],
            'mkdir -m 700 -p '.escapeshellarg($configDir));
    };
    if (!$prepare($user, $home, $dir) || is_link($dir) || !is_dir($dir)) return false;
    $raw = pmssReadRegularFileContents($path);
    if ($raw === null && (file_exists($path) || is_link($path))) return false;
    $updated = pmssTransmissionSettingsContent($raw ?? '{}', $socketPath,
        '/user-'.$user.'/transmission/', $ops['snakeCase'] ?? null);
    if ($updated === null) return false;
    if ($updated === $raw) return true;
    if ($running($user) || $socketLive($socketPath)) return false;
    $write = $ops['write'] ?? static function (string $target, string $content, string $name): bool {
        return pmssReplaceUserFileWithMetadata($target, $content, 0600, $name, $name);
    };
    return $write($path, $updated, $user);
}
