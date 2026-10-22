#!/usr/bin/env php
<?php
/**
 * Operator-side compatibility entrypoint for the customer-delivered installer.
 *
 * Set HOME to the account's home directory. Root installs nothing itself: the
 * account's own ~/bin/docker-install-lsio runs as that account (ADR 0121).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';

/**
 * Return the passwd entry whose home is exactly this account-owned directory, or null.
 *
 * @param callable|null $passwdByUid Injectable passwd lookup for tests.
 * @return array<string,mixed>|null
 */
function pmssDockerInstallLsioOperatorAccount(string $homeDir, ?callable $passwdByUid = null): ?array
{
    $stat = $homeDir !== '' ? @lstat($homeDir) : false;
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0040000 || (int) ($stat['uid'] ?? 0) === 0) {
        return null;
    }
    $account = ($passwdByUid ?? 'posix_getpwuid')((int) $stat['uid']);
    return is_array($account) && ($account['dir'] ?? null) === $homeDir
        && (int) ($account['uid'] ?? 0) === (int) $stat['uid'] ? $account : null;
}

/**
 * Build the account-side command that runs the account's own installer.
 *
 * @param array<string,mixed> $account
 * @param array<int,string> $args
 */
function pmssDockerInstallLsioOperatorCommand(array $account, array $args): string
{
    $home = (string) $account['dir'];
    $command = array_merge(['env', 'HOME='.$home, 'DOCKER_HOST=unix:///run/user/'.(int) $account['uid'].'/docker.sock',
        '/usr/bin/php', $home.'/bin/docker-install-lsio'], array_map('strval', $args));
    return implode(' ', array_map('escapeshellarg', $command));
}

/** Resolve the account from HOME and hand the install to it. */
function pmssDockerInstallLsioOperatorMain(array $argv): int
{
    $account = pmssDockerInstallLsioOperatorAccount((string) getenv('HOME'));
    if ($account === null) {
        fwrite(STDERR, "Set HOME to the account's home directory; the installer runs as that account.\n");
        return 1;
    }
    $rc = 1;
    $command = pmssDockerInstallLsioOperatorCommand($account, array_slice(array_values($argv), 1));
    passthru(pmssBuildUserShellNoPamCommand((string) $account['name'], $command), $rc);
    return (int) $rc;
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssDockerInstallLsioOperatorMain');
