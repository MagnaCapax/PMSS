<?php
/**
 * Fail-closed reconciliation for anonymous legacy rTorrent port markers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/rtorrentPortReservations.php';

/**
 * Remove old markers absent from every readable live-user ownership source.
 *
 * @return array{status:string,reason:string,removed:int,kept:int,errors:int}
 */
function pmssRtorrentPortReservationsReconcile(
    array $users,
    string $homeRoot = '/home',
    string $configRoot = '/etc/seedbox/config',
    string $portsBase = '/var/lib/pmss/ports',
    ?int $now = null,
    int $graceSeconds = PMSS_RTORRENT_PORT_RESERVATION_GRACE_SECONDS,
    ?string $lockPath = null
): array {
    $result = array('status' => 'ok', 'reason' => '', 'removed' => 0, 'kept' => 0, 'errors' => 0);
    $portsBase = rtrim($portsBase, '/');
    if (!pmssPathExistsOrLink($portsBase)) {
        return $result;
    }
    if (!is_dir($portsBase) || is_link($portsBase) || !pmssPathTargetIsSafe($portsBase, true)) {
        return array_replace($result, array('status' => 'skipped', 'reason' => 'unsafe_ports_root'));
    }

    $busy = false;
    $lock = pmssLockFileAcquire($lockPath ?: pmssRtorrentPortReservationLockPath(), true, 'c', true, true, $busy);
    if ($lock === false) {
        return array_replace($result, array('status' => 'skipped', 'reason' => $busy ? 'lock_busy' : 'lock_unavailable'));
    }

    try {
        $references = array();
        $spec = pmssRtorrentPortReservationSpecs()['scgi'];
        foreach ($users as $user) {
            if (!pmssRtorrentPortReservationUsernameIsValid($user)) {
                return array_replace($result, array('status' => 'skipped', 'reason' => 'invalid_user_list'));
            }
            $stored = pmssRtorrentPortReservationStoredSource($user, $configRoot);
            $configured = pmssRtorrentPortReservationConfigSource(rtrim($homeRoot, '/').'/'.$user.'/.rtorrent.rc');
            if (!empty($stored['uncertain']['scgi'])
                || (!isset($stored['ports']['scgi']) && !empty($configured['uncertain']['scgi']))) {
                return array_replace($result, array('status' => 'skipped', 'reason' => 'uncertain_scgi_ownership'));
            }
            foreach (array($stored, $configured) as $source) {
                foreach ($source['ports']['scgi'] ?? array() as $port => $present) {
                    if ($present) $references[(int) $port] = true;
                }
            }
        }

        $directory = $portsBase.'/scgi';
        if (!pmssPathExistsOrLink($directory)) {
            return $result;
        }
        $entries = is_dir($directory) && !is_link($directory) && pmssPathTargetIsSafe($directory, true)
            ? pmssDirectoryEntriesRead($directory)
            : false;
        if (!is_array($entries)) {
            return array_replace($result, array('status' => 'skipped', 'reason' => 'unsafe_marker_directory'));
        }
        $now = $now ?? time();
        $graceSeconds = max(0, $graceSeconds);
        foreach ($entries as $name) {
            $path = $directory.'/'.$name;
            $port = pmssNetworkPortParseDigits($name, $spec['min'], $spec['max']);
            $mtime = @filemtime($path);
            if ($port === null || !is_int($mtime) || !pmssRegularFilePathIsReadable($path)
                || isset($references[$port]) || ($now - $mtime) < $graceSeconds) {
                $result['kept']++;
                continue;
            }
            pmssRtorrentPortReservationMarkerRemove($portsBase, 'scgi', $port)
                ? $result['removed']++
                : $result['errors']++;
        }
        if ($result['errors'] > 0) {
            $result['status'] = 'error';
            $result['reason'] = 'marker_remove_failed';
        }
        return $result;
    } finally {
        pmssLockHandleRelease($lock);
    }
}
