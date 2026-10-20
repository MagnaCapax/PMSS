<?php
/**
 * Termination cleanup primitives for foreground account removal flows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/userLifecycle.php';
require_once dirname(__DIR__).'/pathSafety.php';
require_once __DIR__.'/userConfigStore.php';

/** Account-owned socket trees are removed under the account UID without a PAM session. */
function pmssTerminateUserAccountSocketSteps(string $username, int $uid): array
{
    if (!pmssUsernameIsValid($username) || $uid < 1) {
        throw new InvalidArgumentException('Refusing unsafe account socket cleanup');
    }
    $tmux = '/tmp/tmux-'.$uid;
    $screen = '/var/run/screen/S-'.$username;
    $guardedRemove = static function (string $path) use ($username): string {
        $quoted = escapeshellarg($path);
        $command = 'if [ -e '.$quoted.' ] || [ -L '.$quoted.' ]; then rm -rf -- '.$quoted.'; fi';
        return 'timeout --kill-after=5s 45s '.pmssBuildUserShellNoPamCommand($username, $command);
    };

    return array(
        'remove_tmux_socket' => $guardedRemove($tmux),
        'remove_screen_socket' => $guardedRemove($screen),
    );
}

/** Wait for the manager to leave active/deactivating before clearing failed state. */
function pmssTerminateUserResetFailedCommand(int $uid): string
{
    if ($uid < 1) {
        throw new InvalidArgumentException('Refusing unsafe user manager UID');
    }
    $unit = escapeshellarg('user@'.$uid.'.service');
    return 'for attempt in 1 2 3 4 5 6 7 8 9 10; do '
        .'state=$(timeout --kill-after=1s 5s systemctl is-active '.$unit.' 2>/dev/null); '
        .'rc=$?; if [ "$rc" -eq 124 ] || [ "$rc" -eq 137 ]; then exit "$rc"; fi; '
        .'case "$state" in active|deactivating) sleep 0.5;; *) break;; esac; '
        .'done; case "$state" in active|deactivating) exit 1;; esac; '
        .'if timeout --kill-after=5s 45s systemctl is-failed --quiet '.$unit.'; '
        .'then timeout --kill-after=5s 45s systemctl reset-failed '.$unit.'; '
        .'else rc=$?; if [ "$rc" -eq 124 ] || [ "$rc" -eq 137 ]; then exit "$rc"; fi; fi';
}

function pmssTerminateUserRejectUnsafePath(string $username, string $phase, string $path, string $message, string $contextKey = 'path'): bool
{
    if (!pmssFilesystemPathHasNulByte($path)) {
        return false;
    }

    pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'ERR', $message, array($contextKey => $path));
    return true;
}

function pmssTerminateUserUnlinkPath(string $username, string $phase, string $path, bool $dryRun): bool
{
    if (pmssTerminateUserRejectUnsafePath($username, $phase, $path, 'Refusing unsafe file path')) {
        return false;
    }
    if (!pmssPathExistsOrLink($path)) {
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

    $entries = pmssDirectoryEntriesRead($path);
    if ($entries !== array()) {
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

/**
 * Steps that purge a directory: remove ordinary files, clear immutable flags
 * through verified open inodes on whatever survived, then remove that residue.
 *
 * The ordering is deliberate (Refs #606): a full recursive chattr BEFORE the removal
 * delays inode recovery on large accounts even when nothing is immutable. Running the scan
 * only on the residue keeps the walk bounded to the files rm could not delete, and
 * covers every immutable path in the tree without a list that can drift from what
 * the writers actually mark.
 */
function pmssTerminateUserPurgeDirectorySteps(string $label, string $path): array
{
    // Accept only the exact account paths, including recreate's fixed archive suffix.
    $prefix = $label === 'home' ? '/home/' : '/home/backup-';
    $suffix = $label === 'user_backup_superseded' ? '.superseded' : '';
    $validLabel = in_array($label, array('home', 'user_backup', 'user_backup_superseded'), true);
    $username = substr($path, strlen($prefix));
    if ($suffix !== '' && substr($username, -strlen($suffix)) === $suffix) {
        $username = substr($username, 0, -strlen($suffix));
    } elseif ($suffix !== '') {
        $username = '';
    }
    if (!$validLabel || strpos($path, $prefix) !== 0 || !pmssUsernameIsValid($username)
        || $path !== $prefix.$username.$suffix) {
        throw new InvalidArgumentException('Refusing unsafe user purge path');
    }

    $remove = pmssPinnedTreePurgeCommand($path, false);

    return array(
        array('remove_'.$label.'_initial', $remove),
        array('clear_immutable_'.$label, pmssPinnedTreePurgeCommand($path, true)),
        array('remove_'.$label.'_leftovers', $remove),
    );
}

/** Return the final line, exit code, and audit status after every purge has run. */
function pmssTerminateUserCompletion(string $username, array $paths, array $purgeResults, bool $dryRun, string $lingerRoot = '/var/lib/systemd/linger'): array
{
    if ($dryRun) {
        return array('line' => '## Done. User termination flow completed (dry run).', 'rc' => 0, 'status' => 'OK');
    }

    // Every step must have run. The first removal fails by design when immutable files remain
    // (PMSS writes ~/.trafficData* and ~/.resourceData immutable), so only the immutable clear
    // and the leftover removal decide; a later successful retry does not erase their failure.
    foreach (array('home', 'user_backup', 'user_backup_superseded') as $label) {
        foreach (array('remove_'.$label.'_initial', 'clear_immutable_'.$label, 'remove_'.$label.'_leftovers') as $step) {
            if (!isset($purgeResults[$step])
                || ($purgeResults[$step] !== 0 && $step !== 'remove_'.$label.'_initial')) {
                return array('line' => '## Incomplete: '.$step, 'rc' => 1, 'status' => 'INCOMPLETE');
            }
        }
    }
    if (pmssUserAccountLookup($username) !== null) {
        return array('line' => '## Incomplete: account_still_present', 'rc' => 1, 'status' => 'INCOMPLETE');
    }
    $lingerPath = rtrim($lingerRoot, '/').'/'.$username;
    clearstatcache(true, $lingerPath);
    if (pmssPathExistsOrLink($lingerPath)) {
        return array('line' => '## Incomplete: linger_still_present', 'rc' => 1, 'status' => 'INCOMPLETE');
    }
    foreach ($paths as $label => $path) {
        clearstatcache(true, $path);
        if (pmssPathExistsOrLink($path)) {
            return array('line' => '## Incomplete: '.$label.'_still_present', 'rc' => 1, 'status' => 'INCOMPLETE');
        }
    }
    return array('line' => '## Done. User termination verified.', 'rc' => 0, 'status' => 'OK');
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
        if (!pmssPathExistsOrLink($filePath)) {
            continue;
        }
        $ok = pmssTerminateUserUnlinkPath($username, 'release_rtorrent_'.$type.'_port', $filePath, $dryRun) && $ok;
        $ok = pmssTerminateUserRemoveEmptyDir($username, 'remove_empty_rtorrent_'.$type.'_port_dir', dirname($filePath), $dryRun) && $ok;
    }
    $ok = pmssTerminateUserRemoveEmptyDir($username, 'remove_empty_rtorrent_ports_root', $portsBase, $dryRun) && $ok;
    pmssUserLifecycleContextLog('terminate', 'cleanup_ports', $username, array('status' => $dryRun ? 'SKIP' : ($ok ? 'OK' : 'ERR'), 'ports' => $ports, 'dry_run' => $dryRun));

    return $ok;
}
