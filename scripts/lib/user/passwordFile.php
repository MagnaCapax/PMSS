<?php
/**
 * Update an account's lighttpd credential file with the account's privileges.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';

/** Return the htpasswd status and capture diagnostics without exposing the secret. */
function pmssUserHtpasswdPasswordUpdate(string $username, string $password, string $path, array &$output): int
{
    $output = [];
    if (!pmssUserFilePathIsSafe($path) || !is_dir(dirname($path))) {
        return 1;
    }

    $htpasswdCommand = is_file($path) ? 'htpasswd -b -m' : 'htpasswd -c -b -m';
    $command = sprintf(
        'umask 0077; %s %s %s %s',
        $htpasswdCommand,
        escapeshellarg($path),
        escapeshellarg($username),
        escapeshellarg($password)
    );
    $identity = function_exists('posix_getpwnam') ? @posix_getpwnam($username) : false;
    $alreadyAccount = is_array($identity) && function_exists('posix_geteuid')
        && (int) $identity['uid'] === posix_geteuid();
    $status = 0;
    exec(($alreadyAccount ? $command : pmssBuildUserShellCommand($username, $command)).' 2>&1', $output, $status);

    return $status !== 0 || !pmssUserFilePathIsSafe($path) || !is_file($path) ? ($status ?: 1) : 0;
}
