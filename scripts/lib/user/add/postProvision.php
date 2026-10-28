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
require_once __DIR__.'/../appSwitch.php';

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
    // The shared snapshot writer applies the root:<user> 0640 policy.
    runProvisionStep('Seed quota file', 'php /scripts/cron/updateQuotas.php');

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
}
