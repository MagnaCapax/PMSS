<?php
/**
 * Runtime safety helpers for userConfig.php.
 *
 * Destructive follow-up steps in user reconfiguration must re-check file and
 * process boundaries locally; session lock files can be stale or malformed.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

/**
 * Extract the rTorrent PID from a session lock file.
 */
function pmssUserConfigRtorrentLockPid(string $lockFile): ?int
{
    if (!is_file($lockFile) || is_link($lockFile)) {
        return null;
    }

    $raw = pmssReadRegularFileTrimmed($lockFile);
    if ($raw === null) {
        return null;
    }

    $pidParts = explode(':+', $raw, 2);
    $pidText = trim((string) ($pidParts[0] ?? ''));
    if (preg_match('/^[1-9][0-9]*$/D', $pidText) !== 1) {
        return null;
    }

    $pid = (int) $pidText;
    return $pid > 1 ? $pid : null;
}

/**
 * Read the real UID from a proc status file.
 */
function pmssUserConfigProcStatusUid(int $pid, string $procRoot = '/proc'): ?int
{
    if ($pid <= 1) {
        return null;
    }

    $status = pmssReadRegularFileContents(rtrim($procRoot, '/').'/'.$pid.'/status');
    if ($status === null || preg_match('/^Uid:\s+([0-9]+)\s+/m', $status, $matches) !== 1) {
        return null;
    }

    return (int) $matches[1];
}

/**
 * Confirm a PID still refers to the expected user's rTorrent process.
 */
function pmssUserConfigRtorrentProcessOwnedBy(int $pid, int $uid, string $procRoot = '/proc'): bool
{
    if ($pid <= 1 || $uid < 1000 || pmssUserConfigProcStatusUid($pid, $procRoot) !== $uid) {
        return false;
    }

    $comm = pmssReadRegularFileTrimmed(rtrim($procRoot, '/').'/'.$pid.'/comm');
    return $comm !== null && strpos($comm, 'rtorrent') === 0;
}
