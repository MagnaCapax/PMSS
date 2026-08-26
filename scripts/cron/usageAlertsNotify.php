#!/usr/bin/env php
<?php
/**
 * Notify opted-in users when traffic, disk, or service thresholds are crossed.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/user/selection.php';
require_once __DIR__.'/../lib/user/usageAlertDelivery.php';

requireRoot();
$lock = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-usageAlertsNotify.lock'), true);
if ($lock === false) exit(0);

$result = pmssListManagedUsersResult('/scripts/listUsers.php');
if ((int) $result['exitCode'] !== 0) {
    fwrite(STDERR, "event=usage_alerts result=user_list_failed\n");
    exit(1);
}

$failures = 0;
foreach ($result['users'] as $user) {
    try {
        $status = pmssUsageAlertsNotifyUser($user);
        echo gmdate('c').' event=usage_alerts user='.$user.' result='.$status.PHP_EOL;
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, gmdate('c').' event=usage_alerts user='.$user.' result=failed error='.get_class($exception).PHP_EOL);
    }
}

exit($failures > 0 ? 1 : 0);
