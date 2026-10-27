<?php
/** Account-identity file replacement and locked append. @license GPL-3.0-only */

/**
 * Atomically replace account-owned content as the account itself.
 * Existing entries must be regular files owned by that account. The optional
 * legacy repair accepts a root-owned file only beneath an account-owned parent;
 * the account replaces it through that parent. Other managed entries remain
 * on pmssWriteManagedFile(). The parent must already exist.
 */
function pmssReplaceAccountFile(
    string $username, string $home, string $path, string $content, int $mode, bool $replaceLegacyOwned = false
): bool
{
    $account = pmssAccountPathIdentity($username, $home);
    $existing = @lstat($path);
    $legacyOwner = $replaceLegacyOwned && is_array($account) && isset($account['uid']) && is_array($existing)
        && (int) $existing['uid'] === 0 && @fileowner(dirname($path)) === $account['uid'];
    if (!is_array($account) || !isset($account['uid']) || $mode < 0 || $mode > 0777
        || !pmssUserFilePathIsSafe($path)
        || (is_array($existing) && (!pmssUserFileStatIsSingleRegular($existing)
            || ($existing['uid'] !== $account['uid'] && !$legacyOwner)))) {
        error_log("Unable to replace account file for {$username}: {$path}");
        return false;
    }

    $directory = dirname($path);
    $temporary = $directory.'/'.basename($path).'.pmss-tmp-XXXXXXXX';
    $command = 'tmp=$(mktemp -- '.escapeshellarg($temporary).') || exit 1; '
        .'trap \'rm -f -- "$tmp"\' EXIT; '
        .'cat > "$tmp" && test "$(wc -c < "$tmp")" -eq '.strlen($content)
        .' && chmod '.sprintf('%o', $mode).' -- "$tmp"'
        .' && test ! -L '.escapeshellarg($path)
        .' && { test ! -e '.escapeshellarg($path).' || test -f '.escapeshellarg($path).'; }'
        .' && mv -T -- "$tmp" '.escapeshellarg($path);
    $replaced = pmssAccountPathRun($username, $home, [$path], $command, $content, 0, $timedOut, $failure);
    if (!$replaced) error_log("Unable to replace account file for {$username}: {$path} ({$failure})");
    // The shell replaces the inode outside PHP; invalidate metadata cached for the old path.
    clearstatcache(true, $path);
    return $replaced;
}

/** Keep older ownership layouts writable while normal account files use the account writer. */
function pmssReplaceAccountFileWithLegacyFallback(string $username, string $home, string $path, string $content, int $mode): bool
{
    $account = pmssAccountPathIdentity($username, $home);
    if (!is_array($account) || !isset($account['uid'])
        || @fileowner($home) !== $account['uid']) {
        error_log("Unable to replace account file for {$username}: {$path}");
        return false;
    }
    return pmssReplaceAccountFile($username, $home, $path, $content, $mode, true);
}

/** Append to an account-owned file while holding its append lock. */
function pmssAppendAccountFile(string $username, string $home, string $path, string $content, int $mode, int $timeoutSec = 0, ?bool &$timedOut = null): bool
{
    $timedOut = false;
    $account = pmssAccountPathIdentity($username, $home);
    if (!is_array($account) || !isset($account['uid']) || $mode < 0 || $mode > 0777
        || !pmssUserFilePathIsSafe($path)
        || (file_exists($path) && (!is_file($path) || @fileowner($path) !== $account['uid']
            || @stat($path)['nlink'] !== 1))) {
        return false;
    }

    $target = escapeshellarg($path);
    $command = 'umask 077; test ! -L '.$target
        .' && { test ! -e '.$target.' || test -f '.$target.'; }'
        .' && exec 3>>'.$target.' && flock -w 10 -x 3 && cat >&3'
        .' && chmod '.sprintf('%o', $mode).' -- '.$target;
    return pmssAccountPathRun($username, $home, [$path], $command, $content, $timeoutSec, $timedOut);
}
