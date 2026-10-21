<?php
/** Reversible old-copy lifecycle; never locks accounts or writes home markers. */

require_once dirname(__DIR__).'/userLifecycle.php';
require_once dirname(__DIR__).'/rtorrent/process.php';
require_once dirname(__DIR__).'/toggleAppCatalog.php';
require_once dirname(__DIR__).'/mediaStackWatchdog.php';

/** Parse the new command before any lookup, logging, or mutation. */
function pmssUserMovedParseArguments(array $argv): array
{
    if (count($argv) !== 3) throw new InvalidArgumentException('Usage: userMoved.php <username> --until=YYYY-MM-DD | --clear');
    $username = pmssUsernameNormalizeIfValid((string) $argv[1]);
    if ($username === null) throw new InvalidArgumentException('Invalid username');
    $option = (string) $argv[2];
    if ($option === '--clear') return [$username, null];
    $until = substr($option, 8);
    if (substr($option, 0, 8) !== '--until=' || !pmssUserMovedDateIsValid($until)) {
        throw new InvalidArgumentException('Expected --clear or --until=YYYY-MM-DD with a valid calendar date');
    }
    return [$username, $until];
}

/** Stop only managed applications, preserving the account's SSH/SFTP sessions. */
function pmssUserMovedStopServices(string $username): int
{
    // Use the same rTorrent executor discovery and per-process stops as cron.
    rtorrentProcessKillManagedPids($username, SIGKILL);
    $processes = ['lighttpd', 'php-cgi', 'deluged', 'deluge-web', 'qbittorrent-nox', 'rclone', 'transmission-daemon'];
    foreach (pmssToggleAppCatalogRead() as $row) $processes[] = $row['watchdogProcess'];
    pmssUserWatchdogTerminateProcesses($username, array_values(array_unique($processes)));

    // Media-stack cron is observe-only. Stop its managed sessions as the account,
    // without invoking app toggles that would write Disable markers under home.
    foreach (pmssMediaStackWatchdogAppDefinitions() as $app => $definition) {
        if (!pmssMediaStackWatchdogSessionRunning($username, $app)) continue;
        pmssUserLifecycleStep('moved', $username, 'stop_'.$app,
            pmssBuildUserShellCommand($username, 'tmux kill-session -t '.escapeshellarg('='.$app)), false);
    }
    // This existing wrapper handles rootless namespaces and runs as the account.
    return pmssUserLifecycleStep('moved', $username, 'stop_docker',
        'php '.escapeshellarg('/scripts/util/userDocker.php').' '.escapeshellarg($username).' '.escapeshellarg('stop'), false);
}

/** Persist before stopping so every later watchdog observes the same state. */
function pmssUserMovedApply(string $username, ?string $until, UserConfigStore $store, ?callable $stopper = null, ?callable $refresher = null): int
{
    pmssUserLifecycleContextLogHomeInfo('moved', 'start', $username, '/home/'.$username);
    $rc = 0;
    try {
        // A repeated clear does not need nginx or service work.
        if ($until !== null || pmssUserMovedUntil($username, $store) !== null) {
            if (!$store->setMovedUntil($username, $until)) throw new RuntimeException('Unable to save moved state');
            try {
                if ($until !== null) $rc = (int) ($stopper ?? 'pmssUserMovedStopServices')($username);
            } catch (RuntimeException $error) {
                // The static notice still needs publishing when a stop needs retrying.
                fwrite(STDERR, $error->getMessage()."\n");
                $rc = 1;
            }
            $refreshRc = (int) ($refresher ?? 'pmssUserLifecycleRefreshManagedNginxConfig')('moved', $username, false);
            if ($refreshRc !== 0) $rc = $refreshRc;
        }
    } catch (RuntimeException $error) {
        fwrite(STDERR, $error->getMessage()."\n");
        $rc = 1;
    }
    pmssUserLifecycleContextLogStatusMessage('moved', 'end', $username, $rc === 0 ? 'OK' : 'ERR',
        $rc === 0 ? ($until === null ? 'Moved state cleared' : 'Old copy parked') : 'Moved state needs operator attention',
        ['movedUntil' => $until, 'rc' => $rc]);
    return $rc;
}
