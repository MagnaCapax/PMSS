#!/usr/bin/env php
<?php
/** Supervise opt-in slskd instances after user-owned configuration is secured. */
require_once __DIR__.'/../lib/user/toggleApp.php';

$pmssCheckSlskdLock = pmssCronLockAcquire('checkSlskdInstances', 'pmssCronLockSkipLog', 'pmssUserWatchdogLockAcquire');
pmssUserWatchdogRunService('slskd', 'slskdEnable', ['slskd'], 'slskd stopped due to suspension', [
    pmssUserWatchdogServiceSpec('slskd', static function (string $user): string {
        return pmssToggleAppPrepare($user, 'slskd') ? pmssToggleAppStartCommand($user, 'slskd') : '';
    }, 'slskd start requested', 'slskd', ['securedMarker' => '.pmss/apps/slskd.secured', 'crashLoop' => 'slskd']),
], static function (string $user): array {
    return ['slskd' => pmssToggleAppProcessRunning($user, 'slskd')];
});
