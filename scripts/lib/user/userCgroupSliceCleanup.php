<?php
/**
 * Remove terminated UIDs' persistent slice state before UID reuse.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/userCgroupSliceDropins.php';

/**
 * Remove only a guarded, flat UID drop-in directory of managed regular files.
 *
 * Preflight the entire directory so links and special files cause no deletions.
 * The changed flag also covers partial failures, which still require a reload.
 */
function pmssUserCgroupSliceDirectoryRemove(string $dir, callable $logger, int $owner, bool &$changed): bool
{
    if (preg_match('/\Auser-[1-9][0-9]*\.slice\.d\z/', basename($dir)) !== 1) {
        $logger('[WARN] Refusing non-UID user slice cleanup directory: '.$dir);
        return false;
    }
    if (!pmssPathExistsOrLink($dir)) {
        return true;
    }
    if (!pmssUserCgroupSlicePathIsSafe($dir, true, $owner)) {
        $logger('[WARN] Refusing unsafe user slice cleanup directory: '.$dir);
        return false;
    }
    $entries = pmssDirectoryEntriesRead($dir);
    if ($entries === false) {
        $logger('[WARN] Unable to scan user slice cleanup directory: '.$dir);
        return false;
    }
    foreach ($entries as $entry) {
        if (!pmssUserCgroupSlicePathIsSafe($dir.'/'.$entry, false, $owner)) {
            $logger('[WARN] Refusing unsafe user slice cleanup entry: '.$dir.'/'.$entry);
            return false;
        }
    }
    $ok = true;
    foreach ($entries as $entry) {
        if (@unlink($dir.'/'.$entry)) {
            $changed = true;
        } else {
            $logger('[WARN] Unable to remove user slice drop-in: '.$dir.'/'.$entry);
            $ok = false;
        }
    }
    if ($ok && @rmdir($dir)) {
        $changed = true;
        $logger('Removed user slice drop-in directory: '.$dir);
        return true;
    }
    $logger('[WARN] Unable to remove user slice drop-in directory: '.$dir);
    return false;
}

/**
 * Clear both /etc namespaces only after the name and UID no longer resolve.
 *
 * The caller supplies the current name lookup; the UID check also protects an
 * already-reassigned UID. Dry runs do not mutate files or invoke the reload.
 */
function pmssUserCgroupSliceDropinsCleanup(
    int $uid, bool $accountPresent, bool $dryRun, callable $logger, callable $reload,
    string $systemdRoot = '/etc/systemd', int $owner = 0
): bool {
    if ($uid < 1) {
        $logger('[WARN] Refusing invalid user slice cleanup UID');
        return false;
    }
    if ($dryRun) {
        $logger('[SKIP] Dry run; user slice drop-in directories retained');
        return true;
    }
    if ($accountPresent || !function_exists('posix_getpwuid') || posix_getpwuid($uid) !== false) {
        $logger('[WARN] Account or UID remains; user slice drop-in directories retained');
        return false;
    }
    if (!pmssUserCgroupSlicePathIsSafe($systemdRoot, true, $owner)) {
        $logger('[WARN] Refusing unsafe systemd cleanup root: '.$systemdRoot);
        return false;
    }
    $changed = false;
    $ok = true;
    foreach (['system', 'system.control'] as $namespace) {
        $base = $systemdRoot.'/'.$namespace;
        if (!pmssPathExistsOrLink($base)) {
            continue;
        }
        if (!pmssUserCgroupSlicePathIsSafe($base, true, $owner)) {
            $logger('[WARN] Refusing unsafe systemd cleanup namespace: '.$base);
            $ok = false;
            continue;
        }
        $ok = pmssUserCgroupSliceDirectoryRemove($base.'/user-'.$uid.'.slice.d', $logger, $owner, $changed) && $ok;
    }
    if ($changed && $reload() !== 0) {
        $logger('[WARN] systemd daemon-reload failed after user slice cleanup');
        $ok = false;
    }
    return $ok;
}
