#!/usr/bin/env php
<?php
/** Supervise opt-in Copyparty instances after user-owned configuration is secured. */
require_once __DIR__.'/../lib/user/toggleApp.php';

$pmssCheckCopypartyLock = pmssCronLockAcquire('checkCopypartyInstances', 'pmssCronLockSkipLog', 'pmssUserWatchdogLockAcquire');
pmssUserWatchdogRunService('Copyparty', 'copypartyEnable', ['python3'], 'Copyparty stopped due to suspension', [
    pmssUserWatchdogServiceSpec('copyparty', static function (string $user): string {
        return pmssToggleAppPrepare($user, 'copyparty') ? pmssToggleAppStartCommand($user, 'copyparty') : '';
    }, 'Copyparty start requested', 'Copyparty', ['securedMarker' => '.pmss/apps/copyparty.secured', 'crashLoop' => 'copyparty']),
], static function (string $user): array {
    return ['copyparty' => pmssToggleAppProcessRunning($user, 'copyparty')];
});
