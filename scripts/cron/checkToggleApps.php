#!/usr/bin/env php
<?php
/** Supervise each secured toggle app under one cron lock. */
require_once __DIR__.'/../lib/user/toggleApp.php';

try {
    $catalog = pmssToggleAppCatalogRead();
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
$pmssCheckToggleAppsLock = pmssCronLockAcquire('checkToggleApps', 'pmssCronLockSkipLog', 'pmssUserWatchdogLockAcquire');
try {
    foreach ($catalog as $app => $row) {
        pmssUserWatchdogRunService($row['watchdogLabel'], substr($row['enable'], 1), [$row['watchdogProcess']],
            $row['watchdogLabel'].' stopped: suspended or moved', [
                pmssUserWatchdogServiceSpec($app, static function (string $user) use ($app): string {
                    return pmssToggleAppPrepare($user, $app) ? pmssToggleAppStartCommand($user, $app) : '';
                }, $row['watchdogLabel'].' start requested', $row['watchdogLabel'],
                ['securedMarker' => $row['securedMarker'], 'crashLoop' => $app]),
            ], static function (string $user) use ($app): array {
                return [$app => pmssToggleAppProcessRunning($user, $app)];
            });
    }
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
