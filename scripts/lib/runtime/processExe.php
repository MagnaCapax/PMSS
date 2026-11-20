<?php
/** Process executable identity checks read from /proc. @license GPL-3.0-only */

/**
 * Return whether $pid runs an executable that was replaced on disk after it started.
 *
 * The process counts only when all of these hold, so another account, a container
 * or a reused PID cannot request work from a root caller:
 * - /proc/<pid>/exe ends in " (deleted)" and the path before it starts with $exePrefix;
 * - the process shares this host's mount namespace (rootless containers do not);
 * - /proc/<pid> is owned by $uid, re-checked after the links were read.
 * Anything unreadable, vanished or malformed returns false: no observation, no action.
 *
 * @param callable|null $readlink link reader taking a path, returning string or false; tests inject it
 * @param callable|null $ownerUid owner reader taking a path, returning an int uid or null; tests inject it
 */
function pmssProcessExeDeleted(
    int $pid,
    int $uid,
    string $exePrefix,
    string $procRoot = '/proc',
    ?callable $readlink = null,
    ?callable $ownerUid = null
): bool {
    if ($pid < 1 || $uid < 0 || $exePrefix === '' || strpos($procRoot.$exePrefix, "\0") !== false) {
        return false;
    }
    $readlink = $readlink ?? static function (string $path) { return @readlink($path); };
    $ownerUid = $ownerUid ?? static function (string $path): ?int {
        clearstatcache(true, $path);
        $entry = @stat($path);
        return is_array($entry) && isset($entry['uid']) ? (int) $entry['uid'] : null;
    };
    $procRoot = rtrim($procRoot, '/');
    $procPid = $procRoot.'/'.$pid;

    // Owner first: a process of another account is never inspected further.
    if ($ownerUid($procPid) !== $uid) {
        return false;
    }
    $exe = $readlink($procPid.'/exe');
    $suffix = ' (deleted)';
    if (!is_string($exe) || strlen($exe) <= strlen($suffix) || substr($exe, -strlen($suffix)) !== $suffix) {
        return false;
    }
    if (strpos(substr($exe, 0, -strlen($suffix)), $exePrefix) !== 0) {
        return false;
    }
    $processNamespace = $readlink($procPid.'/ns/mnt');
    $hostNamespace = $readlink($procRoot.'/self/ns/mnt');
    if (!is_string($processNamespace) || $processNamespace === '' || $processNamespace !== $hostNamespace) {
        return false;
    }

    // The PID may have been reused between the reads above; the owner must still match.
    return $ownerUid($procPid) === $uid;
}
