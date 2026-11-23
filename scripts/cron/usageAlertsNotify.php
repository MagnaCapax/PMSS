#!/usr/bin/env php
<?php
/**
 * Notify opted-in users when traffic, disk, or service thresholds are crossed.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';
pmssRequireRelativeFiles(__DIR__.'/../lib', ['user/selection.php', 'user/usageAlertDelivery.php']);

requireRoot();
$lock = pmssCronLockAcquire('usageAlertsNotify', static function (): void { });

$result = pmssListManagedUsersResult('/scripts/listUsers.php');
if ((int) $result['exitCode'] !== 0) {
    exit(pmssCliReturnWithStderr("event=usage_alerts result=user_list_failed\n"));
}

$failures = 0;
foreach ($result['users'] as $user) {
    try {
        $conditionKeys = null;
        $status = pmssUsageAlertsNotifyUser($user, null, null, null, $conditionKeys);
        // Opted-in accounts also log which conditions are active (keys only); a disabled account's line is unchanged.
        echo gmdate('c').' event=usage_alerts user='.$user.' result='.$status
            .($conditionKeys === null ? '' : ' conditions='.implode(',', $conditionKeys)).PHP_EOL;
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, gmdate('c').' event=usage_alerts user='.$user.' result=failed error='.get_class($exception).PHP_EOL);
    }
}

exit($failures > 0 ? 1 : 0);
