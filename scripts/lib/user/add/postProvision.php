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
        sprintf('chmod 640 %s', escapeshellarg("/home/{$user['name']}/.quota"))
    );
    runProvisionStep(
        'Set quota file ownership',
        sprintf('chown root:%s %s', escapeshellarg($user['name']), escapeshellarg("/home/{$user['name']}/.quota"))
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
        pmssTrafficLimitWriteGiBFile($trafficLimitPath, 0) && pmssTrafficLimitConvergeFileMode($trafficLimitPath, 0664);
    }
    if (empty($user['iopsLimit'])) {
        $iopsLimitPath = pmssIopsLimitPath($user['name'], dirname($homePath));
        pmssIopsLimitWriteOperationsFile($iopsLimitPath, 0) && pmssIopsLimitConvergeFileMode($iopsLimitPath, 0664);
    }
}
