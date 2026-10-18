#!/usr/bin/env php
<?php
/**
 * Put the account's AI credits key into ~/.virsilipasKey (ADR 0090), for the platform's key distribution.
 *
 * Called by the provisioning backend through its node command queue, the same way changePw.php is.
 * Root only checks the arguments and the account, then DROPS TO THE ACCOUNT USER before it reads or writes
 * anything in the home: the file is ADR-0046 Customer-editable, so root has no business touching that path.
 *
 * Usage: setVirsilipasKey.php [--jsonl] --create|--update-only <user> <key>
 *   --create       write the file when it is absent (an account that never had a key)
 *   --update-only  replace only an existing file of ours; never create one (rotation: a customer who deleted
 *                  the file keeps it deleted)
 * A file whose endpoint= is not ours, a symlink, or a non-regular file is left alone.
 *
 * Output: one line, never the key. With --jsonl: {"username":..,"result":..,"ts":..}.
 * Results: written, equal, kept-own-endpoint, no-file, symlink, not-regular, unreadable (exit 0, nothing more
 * to do); no-user (exit 3, the account may not be created yet); usage (1), invalid-user (2), invalid-key (4),
 * no-home (5), not-root (6), drop-failed (7), write-failed (8).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/user/identity.php';
require_once __DIR__.'/../lib/user/virsilipasKey.php';

const PMSS_SET_VIRSILIPAS_KEY_EXIT = [
    'written' => 0, 'equal' => 0, 'kept-own-endpoint' => 0, 'no-file' => 0, 'symlink' => 0,
    'not-regular' => 0, 'unreadable' => 0, 'usage' => 1, 'invalid-user' => 2, 'no-user' => 3,
    'invalid-key' => 4, 'no-home' => 5, 'not-root' => 6, 'drop-failed' => 7, 'write-failed' => 8,
];

function pmssSetVirsilipasKeyReport(bool $jsonl, string $username, string $result): int
{
    if ($jsonl) {
        echo json_encode(['username' => $username, 'result' => $result, 'ts' => gmdate('Y-m-d\TH:i:s\Z')],
            JSON_UNESCAPED_SLASHES), "\n";
    } else {
        echo "setVirsilipasKey: {$username}: {$result}\n";
    }
    return PMSS_SET_VIRSILIPAS_KEY_EXIT[$result];
}

function pmssSetVirsilipasKeyCli(array $argv): int
{
    $args = array_slice($argv, 1);
    $jsonl = false;
    $mode = null;
    $rest = [];
    foreach ($args as $arg) {
        if ($arg === '--jsonl') {
            $jsonl = true;
        } elseif (($arg === '--create' || $arg === '--update-only') && $mode === null) {
            $mode = $arg;
        } else {
            $rest[] = $arg;
        }
    }
    if ($mode === null || count($rest) !== 2) {
        fwrite(STDERR, "Usage: setVirsilipasKey.php [--jsonl] --create|--update-only <user> <key>\n");
        return pmssSetVirsilipasKeyReport($jsonl, '', 'usage');
    }
    [$requested, $key] = $rest;

    $username = pmssUsernameNormalizeIfValid((string) $requested);
    if ($username === null || $username !== $requested) {
        return pmssSetVirsilipasKeyReport($jsonl, '', 'invalid-user');
    }
    if (!pmssVirsilipasKeyIsValid((string) $key)) {
        return pmssSetVirsilipasKeyReport($jsonl, $username, 'invalid-key');
    }
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        return pmssSetVirsilipasKeyReport($jsonl, $username, 'not-root');
    }
    $account = posix_getpwnam($username);
    if (!is_array($account) || (int) $account['uid'] === 0) {
        return pmssSetVirsilipasKeyReport($jsonl, $username, 'no-user');
    }
    $home = '/home/'.$username;
    if (!is_dir($home) || is_link($home) || @realpath($home) !== $home) {
        return pmssSetVirsilipasKeyReport($jsonl, $username, 'no-home');
    }

    if (!posix_initgroups($username, (int) $account['gid'])
        || !posix_setgid((int) $account['gid'])
        || !posix_setuid((int) $account['uid'])
        || posix_geteuid() !== (int) $account['uid']) {
        return pmssSetVirsilipasKeyReport($jsonl, $username, 'drop-failed');
    }

    $result = pmssVirsilipasKeyApply($home, (string) $key, $mode === '--create');
    return pmssSetVirsilipasKeyReport($jsonl, $username, $result);
}

exit(pmssSetVirsilipasKeyCli($argv));
