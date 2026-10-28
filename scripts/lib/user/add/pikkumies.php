<?php
/** Creation-time Pikkumies preinstall commands (also used by the update hook). @license GPL-3.0-only */

require_once __DIR__.'/../serviceLaunch.php';
require_once __DIR__.'/../userConfigStore.php';

/** Plan the existing preinstall, with an explicit state directory for hermetic callers. */
function pmssAddUserPikkumiesSwitch(bool $enabled, array $user, string $home, string $stateDir = '/var/lib/pmss/pikkumies-preinstall'): array
{
    if (!$enabled) return ['message' => 'off; preinstall skipped'];
    $command = pmssAddUserPikkumiesPreinstallCommand($user, $home, $stateDir);
    return $command === null
        ? ['message' => sprintf('preinstall skipped: %s MiB RAM (floor %d) or no ~/bin/install-pikkumies.sh', (string) ($user['memory'] ?? '?'), pmssUserPikkumiesPreinstallMinRamMiB())]
        : ['message' => 'preinstall queued', 'queue' => ['Queue Pikkumies preinstall', $command]];
}

/**
 * Background command that preinstalls Pikkumies as the new user, in the user's slice, or null when the
 * account has less RAM than the floor or the skeleton installer is missing. Only the checkout and config
 * are made here; the agent runtime builds later at the account's own daily update time or on first use,
 * so accounts created together never build together (ADR 0090). The terms are asked at the first session.
 */
function pmssAddUserPikkumiesPreinstallCommand(array $user, string $homePath, string $stateDir = '/var/lib/pmss/pikkumies-preinstall'): ?string
{
    $launch = pmssPikkumiesPreinstallLaunch($user, $homePath);
    if ($launch === null) {
        return null;
    }
    // Marked like the update hook marks existing accounts: only when the install exits 0, so a removal by the user is
    // respected later and a failed download is tried again at a later update.
    require_once dirname(__DIR__).'/pikkumiesPreinstallState.php';
    $marker = pmssPikkumiesPreinstallStateMarkerPath((string) ($user['name'] ?? ''), $stateDir);
    $chain = $marker === null ? $launch : '( '.$launch.' ) && touch -- '.escapeshellarg($marker);

    return 'nohup sh -c '.escapeshellarg($chain).' >/dev/null 2>&1 &';
}

/**
 * The foreground install as the user, in the user's slice, or null below the RAM floor or without the skeleton installer.
 * $unlessScheduled: skip it when the user's crontab already has the daily update line (an install that finished before
 * the key arrived), checked as the user so root reads nothing the account controls (ADR 0121).
 */
function pmssPikkumiesPreinstallLaunch(array $user, string $homePath, bool $unlessScheduled = false): ?string
{
    if ((int) ($user['memory'] ?? 0) < pmssUserPikkumiesPreinstallMinRamMiB() || !is_file(rtrim($homePath, '/').'/bin/install-pikkumies.sh')) {
        return null;
    }
    $install = 'PIKKUMIES_SKIP_HERMES=1 bash "$HOME/bin/install-pikkumies.sh"';
    if ($unlessScheduled) $install = 'crontab -l 2>/dev/null | grep -qF "# pikkumies-update" || '.$install;

    return pmssBuildUserServiceShellCommand((string) $user['name'], $install, true);
}
