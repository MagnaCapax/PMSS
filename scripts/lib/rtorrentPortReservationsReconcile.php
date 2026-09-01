<?php
/**
 * Fail-closed reconciliation for anonymous legacy rTorrent port markers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/rtorrentPortReservations.php';

/** Merge one ownership source into the live reference set. */
function pmssRtorrentPortReservationsReferenceMerge(array &$references, array $source): void
{
    foreach ($source['ports'] as $type => $ports) {
        foreach ($ports as $port => $present) {
            if ($present) {
                $references[$type][(int) $port] = true;
            }
        }
    }
}

/** Return the first type whose ownership cannot be determined safely. */
function pmssRtorrentPortReservationsUncertainType(array $stored, array $configured): string
{
    foreach (pmssRtorrentPortReservationSpecs() as $type => $spec) {
        if (!empty($stored['uncertain'][$type])) {
            return $type;
        }
        $known = isset($stored['ports'][$type]);
        if (!$known && !empty($configured['uncertain'][$type])) {
            return $type;
        }
    }
    return '';
}

/** Validate expected directories and collect their entries before deleting. */
function pmssRtorrentPortReservationsMarkerEntries(string $base): ?array
{
    $entries = array();
    foreach (pmssRtorrentPortReservationSpecs() as $type => $spec) {
        $directory = $base.'/'.$type;
        if (!file_exists($directory) && !is_link($directory)) {
            $entries[$type] = array();
            continue;
        }
        if (!is_dir($directory) || is_link($directory) || !pmssPathTargetIsSafe($directory, true)) {
            return null;
        }
        $listed = @scandir($directory);
        if (!is_array($listed)) {
            return null;
        }
        $entries[$type] = array_values(array_diff($listed, array('.', '..')));
    }
    return $entries;
}

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
    if (!file_exists($portsBase) && !is_link($portsBase)) {
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
        foreach ($users as $user) {
            if (!pmssRtorrentPortReservationUsernameIsValid($user)) {
                return array_replace($result, array('status' => 'skipped', 'reason' => 'invalid_user_list'));
            }
            $stored = pmssRtorrentPortReservationStoredSource($user, $configRoot);
            $configured = pmssRtorrentPortReservationConfigSource(rtrim($homeRoot, '/').'/'.$user.'/.rtorrent.rc');
            if (($uncertainType = pmssRtorrentPortReservationsUncertainType($stored, $configured)) !== '') {
                return array_replace($result, array('status' => 'skipped', 'reason' => 'uncertain_'.$uncertainType.'_ownership'));
            }
            pmssRtorrentPortReservationsReferenceMerge($references, $stored);
            pmssRtorrentPortReservationsReferenceMerge($references, $configured);
        }

        $entries = pmssRtorrentPortReservationsMarkerEntries($portsBase);
        if ($entries === null) {
            return array_replace($result, array('status' => 'skipped', 'reason' => 'unsafe_marker_directory'));
        }
        $now = $now ?? time();
        $graceSeconds = max(0, $graceSeconds);
        foreach ($entries as $type => $names) {
            $spec = pmssRtorrentPortReservationSpecs()[$type];
            foreach ($names as $name) {
                $path = $portsBase.'/'.$type.'/'.$name;
                $port = pmssNetworkPortParseDigits($name, $spec['min'], $spec['max']);
                $mtime = @filemtime($path);
                if ($port === null || !is_int($mtime) || !is_file($path) || is_link($path)
                    || isset($references[$type][$port]) || ($now - $mtime) < $graceSeconds) {
                    $result['kept']++;
                    continue;
                }
                pmssRtorrentPortReservationMarkerRemove($portsBase, $type, $port)
                    ? $result['removed']++
                    : $result['errors']++;
            }
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
