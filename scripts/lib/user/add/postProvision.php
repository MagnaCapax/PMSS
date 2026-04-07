<?php
/**
 * addUser: post-provision steps (permissions, seed runtime files).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../../traffic/storage.php';
require_once __DIR__.'/../../user/trafficLimit.php';

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

    // Seed traffic files with zero values so first login does not show errors before cron populates them.
    // Consumers derive display strings from raw counters, so the seed payload stays minimal.
    try {
        $zeroRaw = ['month'=>0.0,'week'=>0.0,'day'=>0.0,'hour'=>0.0,'15min'=>0.0];
        $zeroTraffic = ['raw'=>$zeroRaw,'daily'=>[]];
        $serializedTraffic = serialize($zeroTraffic);
        $trafficPaths = pmssTrafficDataPaths($user['name'], dirname($homePath));
        $runtimeStatsPath = pmssTrafficStatsPath($user['name'], '/var/run/pmss/trafficStats');
        $runtimeStatsDir = dirname($runtimeStatsPath);
        $seedFailed = false;
        if (!pmssEnsureSafeDir($runtimeStatsDir, 0755)) {
            $seedFailed = true;
            logProvisionMessage('Failed to prepare runtime traffic directory');
        }
        // Home files
        foreach (['normal', 'local'] as $pathKey) {
            $trafficPath = $trafficPaths[$pathKey];
            if (!pmssTrafficWriteFile($trafficPath, $serializedTraffic, $user['name'], 0640, true)) {
                $seedFailed = true;
                logProvisionMessage('Failed to seed traffic file: '.$pathKey);
            }
        }
        // Runtime cache
        if (!pmssTrafficWriteFile($runtimeStatsPath, $serializedTraffic, 'root', 0600, false)) {
            $seedFailed = true;
            logProvisionMessage('Failed to seed runtime traffic cache');
        }
        !$seedFailed && logProvisionMessage('Seeded traffic files with zero values');
    } catch (\Throwable $e) {
        logProvisionMessage('Seeding traffic files failed: '.$e->getMessage());
    }

    // Ensure .trafficLimit exists even when no limit is configured at creation time.
    if (empty($user['trafficLimit'])) {
        $trafficLimitPath = pmssTrafficLimitPath($user['name'], dirname($homePath));
        pmssTrafficLimitWriteGiBFile($trafficLimitPath, 0) && pmssTrafficLimitConvergeFileMode($trafficLimitPath, 0664);
    }
}
