#!/usr/bin/env php
<?php
/**
 * Traffic limits enforcement cron.
 *
 * Monitors per-user traffic usage and applies/removes throttling based on
 * configured limits. Throttling is applied when usage exceeds limit and
 * removed after a cooldown period (3 days by default).
 *
 * @author    Aleksi Ursin <123067457+MagnaCapax@users.noreply.github.com>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
require_once '/scripts/lib/network/config.php';
require_once '/scripts/lib/network/fireqos.php';
require_once '/scripts/lib/traffic/storage.php';
require_once '/scripts/lib/user/selection.php';
require_once '/scripts/lib/user/log.php';
require_once '/scripts/lib/user/userConfigStore.php';
require_once __DIR__.'/../lib/user/trafficLimit.php';
require_once '/scripts/lib/runtime.php';

// Serialize runs with the canonical in-script lock (ADR-0049), replacing the
// former root.cron `flock -xn`. Overlapping runs would race per-user throttle
// applies (pmssTrafficLimitThrottleApply) and stack on a hung mount (GH #850/#853).
// Non-blocking: skip if another run holds the lock. Hold the handle until exit.
$trafficLimitsLock = pmssCronLockAcquire('trafficLimits');

if (!pmssDirEnsureExists('/var/run/pmss/trafficLimits', 0755)) {
    exit(pmssCliReturnWithStderr("Unable to prepare traffic limit runtime directory\n"));
}

$trafficLimitPeriod = 3 * 24 * 60 * 60;     // 3 days limiting period

if (($users = pmssListManagedUsersFromResult(pmssListManagedUsersResult('/scripts/listUsers.php'))) === null) {
    exit(1);
}
if (count($users) == 0) die("No users in this system!\n");

$networkConfig = networkLoadConfig();
$throttlePolicy = pmssTrafficLimitThrottlePolicyFromNetworkConfig($networkConfig);
$defaultTrafficCapMbit = $throttlePolicy['defaultTrafficCapMbit'];
$userConfigStore = new UserConfigStore();

foreach($users AS $thisUser) {
    $userTrafficLimitFile = "/etc/seedbox/runtime/trafficLimits/{$thisUser}";
    $trafficDataFile = "/home/{$thisUser}/.trafficData";
    $userTrafficLimitEnabledFile = "/var/run/pmss/trafficLimits/{$thisUser}.enabled";
    $trafficLimitState = pmssTrafficLimitStateRead($userTrafficLimitFile, "/home/{$thisUser}/.bonusTraffic");
    if ($trafficLimitState['effectiveLimitGiB'] <= 0) {
        pmssTrafficLimitThrottleClearUnlimited($thisUser, $userTrafficLimitEnabledFile);
        continue;
    }
    if (!file_exists($trafficDataFile)) continue;

    $data = pmssTrafficReadRootOwnedStatsPayload($trafficDataFile, $thisUser);
    if ($data === null) {
        echo date('Y-m-d H:i:s') . ": Skipping {$thisUser}, invalid traffic data file\n";
        continue;
    }
    $trafficLimit = (float) $trafficLimitState['effectiveLimitGiB'];
    $trafficUsageGiB = (pmssTrafficRawMonthMiB($data) / 1024);   // Set to GiB
    $userConfig = $userConfigStore->get($thisUser);
    $trafficCapMbit = (is_array($userConfig) && isset($userConfig['trafficCapMbit']) && is_numeric($userConfig['trafficCapMbit']))
        ? (int) $userConfig['trafficCapMbit']
        : $defaultTrafficCapMbit;
    $trafficCapMbit = ($trafficCapMbit > 0) ? $trafficCapMbit : $defaultTrafficCapMbit;

    // Enforce traffic throttling: when usage exceeds the configured limit touch the
    // `.enabled` marker (keeping it fresh so sustained overages remain throttled).
    // Once usage drops below the threshold for the configured cooldown window,
    // remove the marker and lift the rate limit. The double disable call guards
    // against occasional router desyncs.
    $overLimit = ($trafficUsageGiB > $trafficLimit);

    // Needs to stay within the limit for X period of time, hence we can always touch & update the limit file
    if ($overLimit) {
        $throttlePlan = pmssTrafficLimitThrottlePlan(
            $trafficLimit,
            $trafficUsageGiB,
            $trafficCapMbit,
            $throttlePolicy['overageThrottleStages'],
            $throttlePolicy['progressiveThrottleEnabled'],
            $throttlePolicy['progressiveThrottleFloorPercent'],
            $throttlePolicy['progressiveThrottleGracePercent']
        );

        if (!pmssTrafficLimitMarkerTouch($thisUser, $userTrafficLimitEnabledFile)) {
            continue;
        }
        pmssTrafficLimitThrottleApply($thisUser, $throttlePlan['effectiveCapMbit']);
        pmssUserLog($thisUser, $throttlePlan['logMessage']);

    } else if (file_exists($userTrafficLimitEnabledFile)) {     // Now let's see if it's time to remove it?

        if ((time() - (int) filemtime($userTrafficLimitEnabledFile)) > $trafficLimitPeriod) {   // Time to remove the limit
            if (!pmssTrafficLimitMarkerRemove($thisUser, $userTrafficLimitEnabledFile)) {
                continue;
            }
            pmssUserLog($thisUser, 'traffic throttle removed after cooldown');
            pmssTrafficLimitThrottleApply($thisUser, $trafficCapMbit, false);
			// Do it second time as removal does not always work for some reason
			sleep(1);
			pmssTrafficLimitThrottleApply($thisUser, $trafficCapMbit, false);
        }

    } else {
        pmssTrafficLimitThrottleOrphanReconcile($thisUser, $trafficCapMbit, $userTrafficLimitEnabledFile);
    }
}

// Rebuild both UID marks and FireQOS classes when the hourly desired caps drift.
if (pmssCommandPath('fireqos') !== '') {
    $desired = array_map(static function (array $user): ?int { return $user['cap']; }, networkFireqosUserCaps($networkConfig, $users));
    $fireqosPath = pmssResolvePathFromEnv('PMSS_FIREQOS_CONFIG_PATH', '/etc/seedbox/config/fireqos.conf');
    $contents = @file_get_contents($fireqosPath);
    $rendered = $contents === false ? null : networkFireqosRenderedUserCaps($contents);
    if (networkFireqosNeedsApply($desired, $rendered)) {
        $changes = [];
        foreach ($desired as $user => $cap) {
            if ($rendered === null || !array_key_exists($user, $rendered)) {
                $changes[] = $user.': added';
            } elseif ($rendered[$user] !== $cap) {
                $changes[] = $user.': '.($rendered[$user] ?? 'none').'->'.($cap ?? 'none');
            }
        }
        foreach ($rendered ?? [] as $user => $cap) {
            if (!array_key_exists($user, $desired)) {
                $changes[] = $user.': removed';
            }
        }
        if ($rendered === null) {
            $changes[] = 'users group missing or unreadable';
        }
        echo date('Y-m-d H:i:s').': FireQOS drift: '.implode(', ', $changes)."\n";
        $output = [];
        $rc = 1;
        exec('timeout 300 /scripts/util/setupNetwork.php >> /var/log/pmss/setupNetwork.log 2>&1', $output, $rc);
        echo date('Y-m-d H:i:s').": setupNetwork exit {$rc}\n";
        $contents = @file_get_contents($fireqosPath);
        $verified = $contents === false ? null : networkFireqosRenderedUserCaps($contents);
        echo date('Y-m-d H:i:s').': '.(networkFireqosNeedsApply($desired, $verified) ? 'verify mismatch' : 'verify ok')."\n";
    }
}
