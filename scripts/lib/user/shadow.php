<?php
/**
 * Shadow password helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

/**
 * Read the active shadow hash for a managed user.
 *
 * Returns an empty string when the entry is missing, locked, or unreadable.
 */
function pmssUserShadowPasswordHashRead(string $username, string $shadowPath = '/etc/shadow'): string
{
    $username = trim($username);
    if (($parts = pmssColonRecordFieldsLookup($shadowPath, $username, 2)) === null) {
        return '';
    }

    $passwordHash = trim((string) $parts[1]);
    if (
        $passwordHash === ''
        || $passwordHash === '*'
        || $passwordHash === '!'
        || strpos($passwordHash, '!') === 0
        || strpos($passwordHash, ':') !== false
        || preg_match('/[\r\n\0]/', $passwordHash) === 1
    ) {
        return '';
    }

    return $passwordHash;
}
