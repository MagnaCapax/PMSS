<?php
/**
 * addUser: post-provision steps (permissions, seed runtime files).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../../traffic/storage.php';

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
    // Format mirrors scripts/lib/traffic/storage.php consumers (serialized array with raw/display/daily keys).
    try {
        $zeroRaw = ['month'=>0.0,'week'=>0.0,'day'=>0.0,'hour'=>0.0,'15min'=>0.0];
        $zeroDisplay = ['month'=>'0MiB','week'=>'0MiB','day'=>'0MiB','hour'=>'0MiB','15min'=>'0MiB'];
        $zeroTraffic = ['raw'=>$zeroRaw,'display'=>$zeroDisplay,'daily'=>[]];
        $trafficPaths = pmssTrafficDataPaths($user['name'], dirname($homePath));
        $runtimeStatsPath = pmssTrafficStatsPath($user['name'], '/var/run/pmss/trafficStats');
        $runtimeStatsDir = dirname($runtimeStatsPath);
        @mkdir($runtimeStatsDir, 0755, true);
        // Home files
        foreach (['normal', 'local'] as $pathKey) {
            $trafficPath = $trafficPaths[$pathKey];
            pmssTrafficSetImmutable($trafficPath, false);
            @file_put_contents($trafficPath, serialize($zeroTraffic));
            @chown($trafficPath, 'root');
            @chgrp($trafficPath, $user['name']);
            @chmod($trafficPath, 0640);
            pmssTrafficSetImmutable($trafficPath, true);
        }
        // Runtime cache
        @file_put_contents($runtimeStatsPath, serialize($zeroTraffic));
        @chown($runtimeStatsPath, 'root');
        @chgrp($runtimeStatsPath, 'root');
        @chmod($runtimeStatsPath, 0600);
        logProvisionMessage('Seeded traffic files with zero values');
    } catch (\Throwable $e) {
        logProvisionMessage('Seeding traffic files failed: '.$e->getMessage());
    }

    // Ensure .trafficLimit exists even when no limit is configured at creation time.
    if (empty($user['trafficLimit'])) {
        $trafficLimitPath = pmssTrafficLimitPath($user['name'], dirname($homePath));
        @file_put_contents($trafficLimitPath, '0');
        @chmod($trafficLimitPath, 0664);
    }
}
