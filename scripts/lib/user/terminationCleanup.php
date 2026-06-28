<?php
/**
 * Termination cleanup primitives for foreground account removal flows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/userLifecycle.php';
require_once __DIR__.'/homeReclaim.php';
require_once __DIR__.'/userConfigStore.php';

function pmssTerminateUserRejectUnsafePath(string $username, string $phase, string $path, string $message, string $contextKey = 'path'): bool
{
    if (!pmssFilesystemPathHasNulByte($path)) {
        return false;
    }

    pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'ERR', $message, array($contextKey => $path));
    return true;
}

function pmssTerminateUserPathExistsOrLink(string $path): bool
{
    return file_exists($path) || is_link($path);
}

function pmssTerminateUserUnlinkPath(string $username, string $phase, string $path, bool $dryRun): bool
{
    if (pmssTerminateUserRejectUnsafePath($username, $phase, $path, 'Refusing unsafe file path')) {
        return false;
    }
    if (!pmssTerminateUserPathExistsOrLink($path)) {
        return true;
    }
    if ($dryRun) {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'SKIP', 'Dry run; file not removed', array('path' => $path));
        return true;
    }
    if (@unlink($path)) {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'OK', 'Removed file', array('path' => $path));
        return true;
    }

    pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'ERR', 'Failed to remove file', array('path' => $path));
    return false;
}

function pmssTerminateUserRemoveEmptyDir(string $username, string $phase, string $path, bool $dryRun): bool
{
    if (pmssTerminateUserRejectUnsafePath($username, $phase, $path, 'Refusing unsafe directory path')) {
        return false;
    }
    if (!is_dir($path)) {
        return true;
    }

    $entries = @scandir($path);
    if (!is_array($entries) || array_diff($entries, array('.', '..')) !== array()) {
        return true;
    }
    if ($dryRun) {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'SKIP', 'Dry run; directory not removed', array('path' => $path));
        return true;
    }
    if (@rmdir($path)) {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'OK', 'Removed empty directory', array('path' => $path));
        return true;
    }

    pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'ERR', 'Failed to remove empty directory', array('path' => $path));
    return false;
}

function pmssTerminateUserNginxRouteFileSpecs(string $username): array
{
    return array(array('phase' => 'remove_nginx_route_file', 'path' => "/etc/nginx/conf.d/pmss-user-{$username}.conf"), array('phase' => 'remove_nginx_route_file_hash', 'path' => "/etc/nginx/conf.d/pmss-user-{$username}-hash.conf"));
}

function pmssTerminateUserRemoveNginxRouteFiles(string $username, bool $dryRun): bool
{
    $ok = true;
    foreach (pmssTerminateUserNginxRouteFileSpecs($username) as $spec) {
        $ok = pmssTerminateUserUnlinkPath($username, $spec['phase'], $spec['path'], $dryRun) && $ok;
    }
    return $ok;
}

function pmssTerminateUserRtorrentPortKeys(): array
{
    return array('scgi' => 'rtorrentPort', 'dht' => 'rtorrentDhtPort', 'listen' => 'rtorrentListenPort');
}

function pmssTerminateUserRtorrentLegacyPatterns(): array
{
    return array('scgi' => '/^scgi_port\s*=\s*(?:[^:]*:)?(\d+)/i', 'dht' => '/^(?:dht_?port|dht\.port(?:\.set)?)\s*=\s*(\d+)/i', 'listen' => '/^port_range\s*=\s*(\d+)(?:\s*-\s*\d+)?/i');
}

function pmssTerminateUserRtorrentPortInvalid(string $username, string $type, string $rawPort, string $message): void
{
    pmssUserLifecycleContextLogStatusMessage('terminate', 'cleanup_ports_config_invalid', $username, 'WARN', $message, array('type' => $type, 'raw_port' => $rawPort));
}

/** @param array<string,int> $ports */
function pmssTerminateUserRtorrentPortRecord(string $username, array &$ports, string $type, string $rawPort): void
{
    if (!array_key_exists($type, pmssTerminateUserRtorrentPortKeys())) {
        pmssTerminateUserRtorrentPortInvalid($username, $type, $rawPort, 'Invalid rTorrent port type in config; skipping port reservation cleanup');
        return;
    }

    $port = pmssNetworkPortParseDigits($rawPort);
    if ($port === null) {
        pmssTerminateUserRtorrentPortInvalid($username, $type, $rawPort, 'Invalid rTorrent port in config; skipping port reservation cleanup');
        return;
    }

    $ports[$type] = $port;
}

function pmssTerminateUserRtorrentStoredPorts(string $username, ?UserConfigStore $store = null): array
{
    $store = $store ?: new UserConfigStore();
    $payload = $store->get($username);
    if (!is_array($payload)) {
        return array();
    }

    $ports = array();
    foreach (pmssTerminateUserRtorrentPortKeys() as $type => $key) {
        if (array_key_exists($key, $payload)) {
            pmssTerminateUserRtorrentPortRecord($username, $ports, $type, (string) $payload[$key]);
        }
    }
    return $ports;
}

/** @param array<string,int> $ports @return array<string,int> */
function pmssTerminateUserRtorrentLegacyPorts(string $username, string $portFile, array $ports): array
{
    if (!file_exists($portFile)) {
        return $ports;
    }

    $configLines = @file($portFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($configLines)) {
        pmssUserLifecycleContextLogStatusMessage('terminate', 'cleanup_ports_config_read', $username, 'WARN', 'Unable to read rTorrent config; skipping reserved port cleanup', array('path' => $portFile));
        return $ports;
    }

    foreach ($configLines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        foreach (pmssTerminateUserRtorrentLegacyPatterns() as $type => $pattern) {
            if (isset($ports[$type]) || preg_match($pattern, $line, $matches) !== 1) {
                continue;
            }
            pmssTerminateUserRtorrentPortRecord($username, $ports, $type, $matches[1]);
            break;
        }
    }

    return $ports;
}

function pmssTerminateUserReleaseRtorrentPortReservations(string $username, string $portFile, bool $dryRun, string $portsBase = '/var/lib/pmss/ports', ?UserConfigStore $store = null): bool
{
    $portsBase = rtrim($portsBase, '/');
    $ports = pmssTerminateUserRtorrentLegacyPorts($username, $portFile, pmssTerminateUserRtorrentStoredPorts($username, $store));
    if (count($ports) === 0 && !file_exists($portFile)) {
        return true;
    }

    $ok = true;
    foreach ($ports as $type => $port) {
        $filePath = $portsBase.'/'.$type.'/'.$port;
        if (!pmssTerminateUserPathExistsOrLink($filePath)) {
            continue;
        }
        $ok = pmssTerminateUserUnlinkPath($username, 'release_rtorrent_'.$type.'_port', $filePath, $dryRun) && $ok;
        $ok = pmssTerminateUserRemoveEmptyDir($username, 'remove_empty_rtorrent_'.$type.'_port_dir', dirname($filePath), $dryRun) && $ok;
    }
    $ok = pmssTerminateUserRemoveEmptyDir($username, 'remove_empty_rtorrent_ports_root', $portsBase, $dryRun) && $ok;
    pmssUserLifecycleContextLog('terminate', 'cleanup_ports', $username, array('status' => $dryRun ? 'SKIP' : ($ok ? 'OK' : 'ERR'), 'ports' => $ports, 'dry_run' => $dryRun));

    return $ok;
}

function pmssTerminateUserMovePathForReclaim(string $username, string $phase, string $sourcePath, bool $dryRun, string $dryRunMessage, string $failureMessage, string $successMessage, array $allocationContext = array(), bool $sourceUnsafe = false): string
{
    if (pmssFilesystemPathHasNulByte($sourcePath) || $sourceUnsafe || is_link($sourcePath)) {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'ERR', 'Refusing unsafe reclaim source path', array('source' => $sourcePath));
        return '';
    }

    $targetPath = pmssUserHomeReclaimPathNext($username);
    if ($targetPath === '') {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'ERR', 'Unable to allocate reclaim path', $allocationContext);
        return '';
    }

    $context = array('source' => $sourcePath, 'target' => $targetPath);
    if ($dryRun) {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'SKIP', $dryRunMessage, $context);
        return $targetPath;
    }
    if (!pmssUserHomeReclaimPathIsSafe($targetPath) || !@rename($sourcePath, $targetPath)) {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'ERR', $failureMessage, $context);
        return '';
    }

    clearstatcache(true, $targetPath);
    if (!pmssUserHomeReclaimPathIsSafe($targetPath)) {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'ERR', 'Renamed reclaim target failed safety validation', $context);
        return '';
    }
    pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'OK', $successMessage, $context);
    return $targetPath;
}

function pmssTerminateUserHomePathIsExact(string $username, string $homePath): bool
{
    if (!pmssUsernameIsValid($username) || pmssFilesystemPathHasNulByte($homePath) || is_link($homePath)) {
        return false;
    }

    $expectedHome = "/home/{$username}";
    $realHome = realpath($homePath);

    return $realHome !== false && $homePath === $expectedHome && $realHome === $expectedHome && is_dir($realHome);
}

function pmssTerminateUserMoveHomeForReclaim(string $username, string $homePath, bool $dryRun): string
{
    if (!pmssTerminateUserHomePathIsExact($username, $homePath)) {
        $realHome = pmssFilesystemPathHasNulByte($homePath) ? false : realpath($homePath);
        pmssUserLifecycleContextLogStatusMessage('terminate', 'home_reclaim_rename', $username, 'ERR', 'Refusing unexpected home reclaim source path', array('expected_home' => "/home/{$username}", 'source' => $homePath, 'real_home' => $realHome));
        return '';
    }

    return pmssTerminateUserMovePathForReclaim($username, 'home_reclaim_rename', $homePath, $dryRun, 'Dry run; home not renamed', 'Failed to rename home for background reclaim', 'Renamed home for background reclaim');
}

function pmssTerminateUserMoveBackupForReclaim(string $username, string $backupPath, bool $dryRun): string
{
    if (is_link($backupPath)) {
        pmssUserLifecycleContextLogStatusMessage('terminate', 'reclaim_user_backup_dir', $username, 'ERR', 'Refusing symlinked user backup directory', array('source' => $backupPath));
        return '';
    }
    if (!is_dir($backupPath)) {
        return '';
    }

    $realBackup = realpath($backupPath);
    if ($realBackup === false || $realBackup !== $backupPath) {
        pmssUserLifecycleContextLogStatusMessage('terminate', 'reclaim_user_backup_dir', $username, 'ERR', 'Refusing unexpected user backup path', array('source' => $backupPath, 'real_backup' => $realBackup));
        return '';
    }

    return pmssTerminateUserMovePathForReclaim($username, 'reclaim_user_backup_dir', $backupPath, $dryRun, 'Dry run; user backup not renamed', 'Failed to rename user backup for background reclaim', 'Renamed user backup for background reclaim', array('source' => $backupPath));
}
