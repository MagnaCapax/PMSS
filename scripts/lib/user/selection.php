<?php
/**
 * Managed-user list parsing and selection helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/identity.php';

/** @param array<int,string> $rawUsers @return array<int,string> */
function pmssManagedUsersNormalizeList(array $rawUsers): array
{
    $users = array();
    foreach ($rawUsers as $rawUser) {
        $trimmed = trim((string) $rawUser);
        if (!pmssValidateUsername($trimmed)) {
            continue;
        }
        $users[pmssNormalizeUsername($trimmed)] = true;
    }
    return array_keys($users);
}

/** Resolve the listUsers command, allowing hermetic tests to supply a fixture. */
function pmssManagedUsersListCommand(string $command): string
{
    $override = getenv('PMSS_TEST_LIST_USERS_COMMAND');
    if (function_exists('pmssTestModeEnabled') && pmssTestModeEnabled() && is_string($override) && trim($override) !== '') {
        return trim($override);
    }

    return $command;
}

/** Return validated managed usernames discovered from local home/account state. */
function pmssManagedHomeUsersList(bool $sort = false): array
{
    require_once dirname(__DIR__).'/users.php';
    $users = pmssManagedUsersNormalizeList(users::listHomeUsers());
    if ($sort) sort($users, SORT_NATURAL | SORT_FLAG_CASE);
    return $users;
}

/** @return array{exitCode:int,users:array<int,string>} */
function pmssListManagedUsersResult(string $command = '/scripts/listUsers.php'): array
{
    $lines = array();
    $exitCode = 0;
    exec(escapeshellarg(pmssManagedUsersListCommand($command)), $lines, $exitCode);
    return array('exitCode' => $exitCode, 'users' => pmssManagedUsersNormalizeList($lines));
}

function pmssListManagedUsersFromResult(array $listUsersResult): ?array
{
    if ((int) ($listUsersResult['exitCode'] ?? 1) === 0) return is_array($listUsersResult['users'] ?? null) ? $listUsersResult['users'] : array();
    fwrite(STDERR, "Error: listUsers.php failed; aborting.\n");
    return null;
}

/** Return trimmed, normalized, validated managed usernames from listUsers.php. */
function pmssListManagedUsers(string $command = '/scripts/listUsers.php'): array
{
    return pmssListManagedUsersResult($command)['users'];
}

/** @return array{exitCode:int,username:string,users:array<int,string>} */
function pmssManagedUsersSelectFromList(array $managedUsers, string $rawUsername = '', array $options = array()): array
{
    $managedUsers = pmssManagedUsersNormalizeList($managedUsers);
    $rawUsername = trim($rawUsername);
    if ($rawUsername === '') {
        if (!empty($options['emitEmptyMessage']) && $managedUsers === []) {
            $message = isset($options['emptyMessage']) ? (string) $options['emptyMessage'] : "No users setup - nothing to do\n";
            !empty($options['emptyToStderr']) ? fwrite(STDERR, $message) : print $message;
        }
        return array('exitCode' => 0, 'username' => '', 'users' => $managedUsers);
    }

    $strictInput = !empty($options['strictInput']);
    $normalizedUsername = pmssNormalizeUsername($rawUsername);
    $username = $strictInput
        ? (($normalizedUsername === $rawUsername && pmssValidateUsername($normalizedUsername)) ? $normalizedUsername : null)
        : pmssUsernameNormalizeIfValid($rawUsername);
    if ($username === null) {
        $message = isset($options['invalidMessage']) ? (string) $options['invalidMessage'] : "Invalid username\n";
        fwrite(STDERR, strpos($message, '%s') === false ? $message : sprintf($message, $normalizedUsername));
        return array('exitCode' => 1, 'username' => $normalizedUsername, 'users' => array());
    }

    $found = (!empty($options['lookupMode']) && $options['lookupMode'] === 'account')
        ? pmssUserAccountLookup($username) !== null
        : in_array($username, $managedUsers, true);
    if (!$found) {
        $message = isset($options['notFoundMessage']) ? (string) $options['notFoundMessage'] : "User not found\n";
        fwrite(STDERR, strpos($message, '%s') === false ? $message : sprintf($message, $username));
        return array('exitCode' => 1, 'username' => $username, 'users' => array());
    }

    return array('exitCode' => 0, 'username' => $username, 'users' => array($username));
}

/** @return array{exitCode:int,username:string,users:array<int,string>} */
function pmssManagedUsersSelectFromCommand(string $command = '/scripts/listUsers.php', string $rawUsername = '', array $options = array()): array
{
    $listUsersResult = pmssListManagedUsersResult($command);
    $exitCode = (int) ($listUsersResult['exitCode'] ?? 1);
    if ($exitCode !== 0) {
        $message = isset($options['commandFailedMessage']) ? (string) $options['commandFailedMessage'] : "Error: listUsers.php failed; aborting.\n";
        if ($message !== '') {
            fwrite(STDERR, strpos($message, '%d') === false ? $message : sprintf($message, $exitCode));
        }

        return array('exitCode' => $exitCode, 'username' => pmssNormalizeUsername($rawUsername), 'users' => array());
    }

    return pmssManagedUsersSelectFromList((array) ($listUsersResult['users'] ?? array()), $rawUsername, $options);
}
