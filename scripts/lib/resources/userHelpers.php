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
    if (function_exists('posix_getpwnam')) {
        $info = @posix_getpwnam($user);
        if (is_array($info) && isset($info['uid'])) {
            return (int) $info['uid'];
        }
    }
    $out = trim((string) @shell_exec('id -u '.escapeshellarg($user).' 2>/dev/null'));
    if ($out === '' || !ctype_digit($out)) {
        return null;
    }
    return (int) $out;
}

/**
 * Load users from listUsers.php and include service accounts when needed.
 */
function pmssResourceLogLoadUsers(): array
{
    $usersRaw = trim((string) @shell_exec('/scripts/listUsers.php'));
    if ($usersRaw === '') {
        return [];
    }
    $users = array_filter(explode("\n", $usersRaw));
    $users[] = 'www-data';
    return $users;
}

/**
 * Validate user entries from listUsers.php output.
 */
function pmssResourceLogIsValidUser(string $user): bool
{
    if ($user === '') {
        return false;
    }
    $normalized = function_exists('pmssNormalizeUsername')
        ? pmssNormalizeUsername($user)
        : strtolower($user);
    if ($normalized !== $user) {
        return false;
    }
    if (!preg_match('/^[a-z0-9-]+$/', $user)) {
        return false;
    }
    if ($user !== 'www-data' && function_exists('pmssValidateUsername') && !pmssValidateUsername($user)) {
        return false;
    }
    return true;
}
