<?php
/**
 * Per-user notification email reader.
 *
 * The billing system provisions this authoritative value. Consumers must
 * reject customer-writable files so alerts cannot be redirected locally.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../pathSafety.php';

/** Normalize one syntactically valid email address from provisioned text. */
function pmssUserNotificationEmailParse(string $raw): ?string
{
    $email = trim($raw, " \t\n\r");
    if ($email === ''
        || strlen($email) > 254
        || preg_match('/[\r\n\0\s]/', $email) === 1
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        return null;
    }

    return $email;
}

/** Read one trusted notification email without following symlinks. */
function pmssUserNotificationEmailRead(string $home, int $expectedGroup): ?string
{
    $home = rtrim($home, '/');
    $path = $home.'/.notifyEmail';
    $pathStat = @lstat($path);
    if ($home === ''
        || $expectedGroup < 0
        || !is_array($pathStat)
        || (($pathStat['mode'] ?? 0) & 0170000) !== 0100000
    ) {
        return null;
    }

    $handle = @fopen($path, 'rb');
    $handleStat = null;
    $openedPathMatches = pmssLockFileHandleMatchesPath($handle, $path, $pathStat, $handleStat);
    $pathAfterOpen = @lstat($path);
    $permissions = is_array($handleStat) ? ($handleStat['mode'] ?? 0) & 0777 : -1;
    if (!$openedPathMatches
        || !is_array($pathAfterOpen)
        || ($pathAfterOpen['dev'] ?? null) !== ($handleStat['dev'] ?? null)
        || ($pathAfterOpen['ino'] ?? null) !== ($handleStat['ino'] ?? null)
        || ($handleStat['uid'] ?? -1) !== 0
        || ($handleStat['gid'] ?? -1) !== $expectedGroup
        || !in_array($permissions, [0600, 0640], true)
    ) {
        if (is_resource($handle)) {
            @fclose($handle);
        }
        return null;
    }

    $raw = @stream_get_contents($handle, 257);
    @fclose($handle);
    return !is_string($raw) || strlen($raw) > 256 ? null : pmssUserNotificationEmailParse($raw);
}
