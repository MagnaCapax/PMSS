<?php
/**
 * User helper functions for resource metering scripts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Resolve a username to its UID with a POSIX-first fallback.
 */
function pmssResourceLogLookupUid(string $user): ?int
{
    if (function_exists('posix_getpwnam') && is_array($info = @posix_getpwnam($user)) && isset($info['uid'])) {
        return (int) $info['uid'];
    }
    $out = trim((string) @shell_exec('id -u '.escapeshellarg($user).' 2>/dev/null'));
    return ctype_digit($out) ? (int) $out : null;
}

/**
 * Load users from listUsers.php and include service accounts when needed.
 */
function pmssResourceLogLoadUsers(): array
{
    $users = array_filter(array_map('trim', explode("\n", (string) @shell_exec('/scripts/listUsers.php'))), 'strlen');
    return empty($users) ? [] : array_merge($users, ['www-data']);
}

/**
 * Validate user entries from listUsers.php output.
 */
function pmssResourceLogIsValidUser(string $user): bool
{
    return (function_exists('pmssNormalizeUsername') ? pmssNormalizeUsername($user) : strtolower($user)) === $user
        && preg_match('/^[a-z0-9-]+$/', $user)
        && ($user === 'www-data'
        || !function_exists('pmssValidateUsername')
        || pmssValidateUsername($user));
}
