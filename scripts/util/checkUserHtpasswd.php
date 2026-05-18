#!/usr/bin/env php
<?php
/**
 * Synchronise legacy global lighttpd htpasswd entries to per-user instances.
 *
 * Intended for transitional environments where a global /etc/lighttpd/.htpasswd
 * file may still contain valid credentials. Modern deployments should favour
 * per-user auth from the start.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/lighttpd/userFileWrite.php';

/**
 * Check whether the target htpasswd file already appears to contain the user.
 *
 * Returns null when the file exists but cannot be read so callers can skip
 * destructive writes instead of appending blindly.
 */
function pmssCheckUserHtpasswdHasUserEntry(string $path, string $username)
{
    $username = trim($username);
    if (!pmssValidateUsername($username)) {
        return false;
    }

    if (!is_file($path)) {
        return false;
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        return null;
    }

    return strpos($contents, $username) !== false;
}

function pmssCheckUserHtpasswdMain(array $argv): int
{
    $argUserRaw = trim((string) ($argv[1] ?? ''));
    $selection = pmssManagedUsersSelectFromCommand('/scripts/listUsers.php', $argUserRaw, array('emitEmptyMessage' => true, 'lookupMode' => 'account', 'strictInput' => true));
    if ($selection['exitCode'] !== 0 || $selection['users'] === array()) return $selection['exitCode'];
    $users = $selection['users'];
    $globalHtpasswd = '/etc/lighttpd/.htpasswd';
    if (trim((string) ($globalContents = @file_get_contents($globalHtpasswd))) === '') {
        if ($argUserRaw === '') {
            echo "Global htpasswd file missing or empty, skipping synchronization\n";
        }
        return 0;
    }

    $passwords = array_filter(explode("\n", $globalContents), 'strlen');

    foreach ($users as $thisUser) {
        if (!pmssValidateUsername($thisUser)) {
            pmssUserLifecycleContextLogStatusMessage('htpasswd',
                'validate',
                trim((string) $thisUser),
                'ERR',
                'Skipping htpasswd sync for invalid username'
            );
            continue;
        }

        $userHtpasswd = "/home/{$thisUser}/.lighttpd/.htpasswd";
        $hasExistingEntry = pmssCheckUserHtpasswdHasUserEntry($userHtpasswd, $thisUser);
        if ($hasExistingEntry === null) {
            pmssUserLifecycleContextLogStatusMessage('htpasswd',
                'read',
                $thisUser,
                'ERR',
                'Unable to read per-user htpasswd; skipping synchronization',
                [
                    'path' => $userHtpasswd,
                ]
            );
            continue;
        }
        if ($hasExistingEntry) {
            continue;
        }

        foreach ($passwords as $thisPassword) {
            if (strpos($thisPassword, $thisUser.':') !== 0) {
                continue;
            }

            if (!pmssAppendUserFile($userHtpasswd, $thisPassword."\n", $thisUser, 0640)) {
                pmssUserLifecycleContextLogStatusMessage('htpasswd',
                    'write',
                    $thisUser,
                    'ERR',
                    'Unable to append legacy credential to per-user htpasswd',
                    [
                        'path' => $userHtpasswd,
                    ]
                );
                continue;
            }

            pmssUserLifecycleStep(
                'htpasswd',
                $thisUser,
                'chown_htpasswd',
                'chown '.escapeshellarg($thisUser.':'.$thisUser).' '.escapeshellarg($userHtpasswd),
                false
            );
            pmssUserLog($thisUser, 'htpasswd sync: appended legacy credential to per-user .htpasswd');
        }
    }

    return 0;
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssCheckUserHtpasswdMain');
