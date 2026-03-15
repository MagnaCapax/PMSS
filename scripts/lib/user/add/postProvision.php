<?php
/**
 * addUser: post-provision steps (permissions, seed runtime files).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Start per-user services and refresh shared runtime state.
 */
function pmssAddUserServicesStart(array $user): void
{
    runProvisionStep(
        'Start rTorrent',
        sprintf('/scripts/startRtorrent %s', escapeshellarg($user['name']))
    );
    runProvisionStep(
        'Start lighttpd',
        sprintf('/scripts/startLighttpd %s', escapeshellarg($user['name']))
    );
    runProvisionStep('Restart nginx', 'systemctl restart nginx || /etc/init.d/nginx restart || true');
    runProvisionStep('Refresh network rules', '/scripts/util/setupNetwork.php');
}

/**
 * Persist optional traffic limits to runtime and the user's home directory.
 */
function pmssAddUserTrafficLimitApply(array $user): void
{
    if (empty($user['trafficLimit']) || $user['trafficLimit'] <= 0) {
        return;
    }

    $runtimeDir = '/etc/seedbox/runtime/trafficLimits';
    require_once __DIR__.'/../directories.php';
    if (function_exists('pmssEnsureDir')) {
        pmssEnsureDir($runtimeDir, 0700, 'root', 'root');
    } elseif (!is_dir($runtimeDir)) {
        @mkdir($runtimeDir, 0755, true);
        @chmod($runtimeDir, 0700);
    }

    @file_put_contents($runtimeDir."/{$user['name']}", (string) $user['trafficLimit'], LOCK_EX);
    @chmod($runtimeDir."/{$user['name']}", 0600);
    @file_put_contents("/home/{$user['name']}/.trafficLimit", (string) $user['trafficLimit'], LOCK_EX);
    @chmod("/home/{$user['name']}/.trafficLimit", 0664);
    logProvisionMessage('Traffic limit set: '.$user['trafficLimit']);
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
        $runtimeStatsDir = '/var/run/pmss/trafficStats';
        $chattrPath = null;
        // Best-effort immutable toggle for traffic data files.
        $setImmutable = static function (string $path, bool $enable) use (&$chattrPath): void {
            if (!is_file($path)) {
                return;
            }
            if ($chattrPath === null) {
                $chattrPath = '';
                foreach (['/usr/bin/chattr', '/bin/chattr'] as $candidate) {
                    if (is_executable($candidate)) {
                        $chattrPath = $candidate;
                        break;
                    }
                }
            }
            if ($chattrPath === '') {
                return;
            }
            @exec($chattrPath.' '.($enable ? '+i' : '-i').' '.escapeshellarg($path).' 2>/dev/null');
        };
        @mkdir($runtimeStatsDir, 0755, true);
        // Home files
        foreach (['.trafficData', '.trafficDataLocal'] as $suffix) {
            $trafficPath = $homePath.'/'.$suffix;
            $setImmutable($trafficPath, false);
            @file_put_contents($trafficPath, serialize($zeroTraffic));
            @chown($trafficPath, 'root');
            @chgrp($trafficPath, $user['name']);
            @chmod($trafficPath, 0640);
            $setImmutable($trafficPath, true);
        }
        // Runtime cache
        @file_put_contents("$runtimeStatsDir/{$user['name']}", serialize($zeroTraffic));
        @chown("$runtimeStatsDir/{$user['name']}", 'root');
        @chgrp("$runtimeStatsDir/{$user['name']}", 'root');
        @chmod("$runtimeStatsDir/{$user['name']}", 0600);
        logProvisionMessage('Seeded traffic files with zero values');
    } catch (\Throwable $e) {
        logProvisionMessage('Seeding traffic files failed: '.$e->getMessage());
    }

    // Ensure .trafficLimit exists even when no limit is configured at creation time.
    if (empty($user['trafficLimit'])) {
        @file_put_contents("/home/{$user['name']}/.trafficLimit", '0');
        @chmod("/home/{$user['name']}/.trafficLimit", 0664);
    }
}
