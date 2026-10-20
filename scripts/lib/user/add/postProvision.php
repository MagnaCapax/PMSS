<?php
/**
 * addUser: post-provision steps (permissions, seed runtime files).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../../traffic/storage.php';
require_once __DIR__.'/../../user/trafficLimit.php';
require_once __DIR__.'/../../user/iopsLimit.php';
require_once __DIR__.'/../../lighttpd/userFileWrite.php';
require_once __DIR__.'/../../userLifecycle.php';
require_once __DIR__.'/../serviceLaunch.php';
require_once __DIR__.'/../userConfigStore.php';
require_once __DIR__.'/appProfile.php';

/** Persist creation-time additional quota for immediate panel visibility. */
function pmssAddUserBonusQuotaPersist(array $user, string $homePath): bool
{
    if (!array_key_exists('bonusQuotaGiB', $user)) {
        return true;
    }
    if (!is_int($user['bonusQuotaGiB']) || $user['bonusQuotaGiB'] < 0) {
        return false;
    }
    if ($user['bonusQuotaGiB'] === 0) {
        return true;
    }
    if (!isset($user['name']) || !is_string($user['name']) || !pmssValidateUsername($user['name'])) {
        return false;
    }

    return pmssReplaceAccountFileWithLegacyFallback(
        $user['name'],
        rtrim($homePath, '/'),
        rtrim($homePath, '/').'/.bonusQuota',
        (string) $user['bonusQuotaGiB'],
        0640
    );
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

/** The foreground install as the user, in the user's slice, or null below the RAM floor or without the skeleton installer. */
function pmssPikkumiesPreinstallLaunch(array $user, string $homePath): ?string
{
    if ((int) ($user['memory'] ?? 0) < pmssUserPikkumiesPreinstallMinRamMiB() || !is_file(rtrim($homePath, '/').'/bin/install-pikkumies.sh')) {
        return null;
    }

    return pmssBuildUserServiceShellCommand((string) $user['name'], 'PIKKUMIES_SKIP_HERMES=1 bash "$HOME/bin/install-pikkumies.sh"', true);
}

/**
 * Run post-provision steps that should not block account creation.
 */
function pmssAddUserPostProvision(array $user, string $homePath): void
{

    // Setting file permissions
    runProvisionStep(
        'Queue permissions fix',
        sprintf('nohup /scripts/util/userPermissions.php %s >> /dev/null 2>&1 &', escapeshellarg($user['name']))
    );

    // Seed per-user quota file by invoking the same refresher used by cron to avoid duplication.
    // Then normalize permissions to 0640 to align with userPermissions policy.
    runProvisionStep('Seed quota file', 'php /scripts/cron/updateQuotas.php');
    runProvisionStep(
        'Normalize quota file permissions',
        sprintf('find %s -maxdepth 0 -type f -links 1 -exec chmod 640 {} +', escapeshellarg("/home/{$user['name']}/.quota"))
    );
    runProvisionStep(
        'Set quota file ownership',
        sprintf('find %s -maxdepth 0 -type f -links 1 -exec chown -h %s {} +', escapeshellarg("/home/{$user['name']}/.quota"), escapeshellarg('root:'.$user['name']))
    );

    try {
        pmssTrafficSeedInitialState($user['name'], dirname($homePath), null, 'logProvisionMessage')
            && logProvisionMessage('Seeded traffic files with zero values');
    } catch (\Throwable $e) {
        logProvisionMessage('Seeding traffic files failed: '.$e->getMessage());
    }

    // Ensure .trafficLimit exists even when no limit is configured at creation time.
    if (empty($user['trafficLimit'])) {
        $trafficLimitPath = pmssTrafficLimitPath($user['name'], dirname($homePath));
        pmssIntegerSettingFileWrite($trafficLimitPath, 0) && pmssIntegerSettingPathModeConverge($trafficLimitPath, 0664);
    }
    if (empty($user['iopsLimit'])) {
        $iopsLimitPath = pmssIopsLimitPath($user['name'], dirname($homePath));
        pmssIntegerSettingFileWrite($iopsLimitPath, 0) && pmssIntegerSettingPathModeConverge($iopsLimitPath, 0664);
    }

    if (!pmssAddUserBonusQuotaPersist($user, $homePath)) {
        logProvisionMessage('Bonus quota persistence failed');
    } elseif (!empty($user['bonusQuotaGiB'])) {
        logProvisionMessage('Bonus quota recorded: '.$user['bonusQuotaGiB'].' GiB');
    }

    if (($user['appProfile'] ?? '') === 'openclaw') {
        pmssAddUserOpenClawPreinstall($user, $homePath);
        return;
    }
    $pikkumies = pmssAddUserPikkumiesPreinstallCommand($user, $homePath);
    if ($pikkumies === null) {
        logProvisionMessage(sprintf('Pikkumies not preinstalled: %s MiB RAM (floor %d) or no ~/bin/install-pikkumies.sh', (string) ($user['memory'] ?? '?'), pmssUserPikkumiesPreinstallMinRamMiB()));
    } else {
        runProvisionStep('Queue Pikkumies preinstall', $pikkumies);
    }
}
