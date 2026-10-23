<?php
/**
 * Guard and demote legacy per-UID systemd slice defaults.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/userCgroupSliceDropinRepair.php';

/** Require a managed inode without group/world write or symlinked components. */
function pmssUserCgroupSlicePathIsSafe(string $path, bool $directory, int $owner = 0): bool
{
    if (!pmssPathAbsoluteStringIsSafe($path, ['allowTrailingSlash' => false])
        || !pmssPathSegmentsAreSafe($path, false, false, true, $directory)) {
        return false;
    }
    clearstatcache(true, $path);
    $stat = @lstat($path);
    return is_array($stat) && $stat['uid'] === $owner && ($stat['mode'] & 0022) === 0
        && ($stat['mode'] & 0170000) === ($directory ? 0040000 : 0100000);
}

/** Back up before moving one legacy file below systemctl's 50-* properties. */
function pmssUserCgroupSliceDropinDemote(string $source, string $target, callable $logger, int $owner = 0): bool
{
    if (!pmssPathExistsOrLink($source)) {
        return false;
    }
    $names = ['90-pmss-user.conf' => '40-pmss-user-legacy.conf', '99-pmss.conf' => '41-pmss-legacy.conf'];
    if (preg_match('/\Auser-(?:0|[1-9][0-9]*)\.slice\.d\z/', basename(dirname($source))) !== 1
        || !isset($names[basename($source)])
        || $target !== dirname($source).'/'.$names[basename($source)]
        || !pmssUserCgroupSlicePathIsSafe(dirname($source), true, $owner)
        || !pmssUserCgroupSlicePathIsSafe($source, false, $owner)
        || pmssPathExistsOrLink($target)) {
        $logger('[WARN] Skipping unsafe or occupied legacy user slice drop-in: '.$source);
        return false;
    }
    $backup = pmssCreateManagedPathBackup($source, 'user slice drop-in', $logger, date('YmdHis'));
    // Preserve every legacy key and its metadata; never replace another drop-in.
    if ($backup === '') {
        return false;
    }
    if (!@rename($source, $target)) {
        $logger('[WARN] Unable to demote legacy user slice drop-in: '.$source);
        return false;
    }
    $logger('Demoted legacy user slice drop-in '.$source.' to '.$target.' (backup '.$backup.')');
    return true;
}

/**
 * Repair exact UID directories, including orphaned UIDs, then reload once.
 *
 * Explicit directory/owner arguments let unprivileged tests use isolated fixtures.
 * Returns true only when files changed and the batch reload succeeded.
 */
function pmssUserCgroupSliceDropinsRepair(
    callable $logger, callable $reload, string $systemDir = '/etc/systemd/system', int $owner = 0
): bool {
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        $logger('[SKIP] Dry run; legacy user slice drop-ins retained');
        return false;
    }
    if (!pmssUserCgroupSlicePathIsSafe($systemDir, true, $owner)) {
        $logger('[WARN] Skipping unsafe systemd slice directory: '.$systemDir);
        return false;
    }
    $entries = pmssDirectoryEntriesRead($systemDir);
    if ($entries === false) {
        $logger('[WARN] Unable to scan systemd slice directory: '.$systemDir);
        return false;
    }
    $changed = false;
    foreach ($entries as $entry) {
        if (preg_match('/\Auser-(?:0|[1-9][0-9]*)\.slice\.d\z/', $entry) !== 1) {
            continue;
        }
        $dir = $systemDir.'/'.$entry;
        if (!pmssUserCgroupSlicePathIsSafe($dir, true, $owner)) {
            $logger('[WARN] Skipping unsafe user slice directory: '.$dir);
            continue;
        }
        // Keep the former 99-file after the former 90-file, both before 50-*.
        foreach (['90-pmss-user.conf' => '40-pmss-user-legacy.conf', '99-pmss.conf' => '41-pmss-legacy.conf'] as $old => $new) {
            $changed = pmssUserCgroupSliceDropinDemote($dir.'/'.$old, $dir.'/'.$new, $logger, $owner) || $changed;
        }
    }
    if ($changed && $reload() !== 0) {
        $logger('[WARN] systemd daemon-reload failed after legacy user slice repair');
        return false;
    }
    return $changed;
}
