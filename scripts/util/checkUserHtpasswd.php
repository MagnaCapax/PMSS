#!/usr/bin/env php
<?php
/**
 * Synchronise legacy global lighttpd htpasswd entries to per-user instances.
 *
 * Intended for transitional environments where a global /etc/lighttpd/.htpasswd
 * file may still contain valid credentials. Modern deployments should favour
 * per-user auth from the start.
 *
 * @author    Aleksi Ursin <123067457+MagnaCapax@users.noreply.github.com>
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

    if (!file_exists($path) && !is_link($path)) {
        return false;
    }

    if (pmssManagedAccountHomePath($path)) {
        $identity = pmssAccountHomeIdentity($path);
        if ($identity === null || !pmssUserFilePathIsSafe($path)) return null;
        list($home, $user) = $identity;
        $target = escapeshellarg($path);
        // Both the size check and content search execute as the account.
        if (!pmssAccountPathRun($user, $home, [$path],
            'test -f '.$target.' && test -r '.$target
            .' && test "$(stat -c %s -- '.$target.')" -le 65536')) return null;
        return pmssAccountPathRun($user, $home, [$path],
            'grep -Fq -- '.escapeshellarg($username).' '.$target);
    }

    $contents = pmssReadRegularFileContents($path);
    if ($contents === null) {
        return null;
    }

    return strpos($contents, $username) !== false;
}

/** Copy a legacy file and append as the account; root never reads through its directory. */
function pmssCheckUserHtpasswdLegacyAppend(string $username, string $home, string $path, string $content): bool
{
    $account = pmssAccountPathIdentity($username, $home);
    $entry = @lstat($path);
    if (!is_array($account) || !is_array($entry) || $entry['uid'] !== 0
        || ($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1
        || @fileowner(dirname($path)) !== $account['uid']
        || !pmssUserFilePathIsSafe($path)) return false;
    $target = escapeshellarg($path);
    $temporary = escapeshellarg(dirname($path).'/.htpasswd.pmss-tmp-XXXXXXXX');
    $command = 'test ! -L '.$target.' && test -f '.$target
        .' && test "$(stat -c %h -- '.$target.')" -eq 1'
        .' && test "$(stat -c %s -- '.$target.')" -le 65536'
        .' && tmp=$(mktemp -- '.$temporary.') || exit 1; '
        .'trap \'rm -f -- "$tmp"\' EXIT; '
        .'cat -- '.$target.' > "$tmp" && cat >> "$tmp"'
        .' && chmod 640 -- "$tmp" && mv -T -- "$tmp" '.$target;
    return pmssAccountPathRun($username, $home, [$path], $command, $content);
}

function pmssCheckUserHtpasswdMain(array $argv): int
{
    $argUserRaw = trim((string) ($argv[1] ?? ''));
    $selection = pmssManagedUsersSelectFromCommand('/scripts/listUsers.php', $argUserRaw, array('emitEmptyMessage' => true, 'lookupMode' => 'account', 'strictInput' => true));
    if ($selection['exitCode'] !== 0 || $selection['users'] === array()) return $selection['exitCode'];
    $globalHtpasswd = '/etc/lighttpd/.htpasswd';
    if (trim((string) ($globalContents = @file_get_contents($globalHtpasswd))) === '') {
        if ($argUserRaw === '') {
            echo "Global htpasswd file missing or empty, skipping synchronization\n";
        }
        return 0;
    }

    $passwords = pmssNonEmptyStrings(explode("\n", $globalContents));

    foreach ($selection['users'] as $thisUser) {
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

            $account = function_exists('posix_getpwnam') ? @posix_getpwnam($thisUser) : false;
            $olderOwner = is_array($account) && is_file($userHtpasswd)
                && @fileowner($userHtpasswd) !== $account['uid'];
            // Older files are replaced as the account through their writable parent.
            $written = $olderOwner
                ? pmssCheckUserHtpasswdLegacyAppend(
                    $thisUser, "/home/{$thisUser}", $userHtpasswd, $thisPassword."\n")
                : pmssAppendAccountFile($thisUser, "/home/{$thisUser}", $userHtpasswd, $thisPassword."\n", 0640);
            if (!$written) {
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

            pmssUserLog($thisUser, 'htpasswd sync: appended legacy credential to per-user .htpasswd');
        }
    }

    return 0;
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssCheckUserHtpasswdMain');
