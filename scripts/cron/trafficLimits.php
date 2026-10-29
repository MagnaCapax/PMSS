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
    // Root's runtime copy (0600); the home copy sits in a directory the account can change.
    $trafficDataFile = pmssTrafficStatsPath($thisUser);
    $userTrafficLimitEnabledFile = "/var/run/pmss/trafficLimits/{$thisUser}.enabled";
    $trafficLimitState = pmssTrafficLimitStateRead($userTrafficLimitFile, "/home/{$thisUser}/.bonusTraffic");
    if ($trafficLimitState['effectiveLimitGiB'] <= 0) {
        pmssTrafficLimitThrottleClearUnlimited($thisUser, $userTrafficLimitEnabledFile);
        continue;
    }
    if (!file_exists($trafficDataFile)) {
        // Usage is unknown, not under limit: lift only a throttle that
        // nothing has reasserted for a full cooldown.
        pmssTrafficLimitThrottleReconcileWithoutUsage($thisUser, $userTrafficLimitEnabledFile, $trafficLimitPeriod);
        continue;
    }

    $data = pmssTrafficReadRootOwnedStatsPayload($trafficDataFile, $thisUser);
    if ($data === null) {
        echo pmssLogFormatTimestampedLine("Skipping {$thisUser}, invalid traffic data file");
        pmssTrafficLimitThrottleReconcileWithoutUsage($thisUser, $userTrafficLimitEnabledFile, $trafficLimitPeriod);
        continue;
    }
    $trafficLimit = (float) $trafficLimitState['effectiveLimitGiB'];
    $trafficUsageGiB = (pmssTrafficRawMonthMiB($data) / 1024);   // Set to GiB
    $userConfig = $userConfigStore->get($thisUser);
    // A cap the plan sets outranks the default overage stages (#1056).
    $planCapMbit = pmssTrafficLimitPlanCapMbit($userConfig);
    $planCapSet = ($planCapMbit > 0);
    $trafficCapMbit = $planCapSet ? $planCapMbit : $defaultTrafficCapMbit;

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
            $throttlePolicy['progressiveThrottleGracePercent'],
            $planCapSet
        );

        if (!pmssTrafficLimitMarkerTouch($thisUser, $userTrafficLimitEnabledFile)) {
            continue;
        }
        pmssTrafficLimitThrottleApply($thisUser, $throttlePlan['effectiveCapMbit']);
        pmssUserLog($thisUser, $throttlePlan['logMessage']);

    } else if (file_exists($userTrafficLimitEnabledFile)) {     // Now let's see if it's time to remove it?

        if ((time() - (int) filemtime($userTrafficLimitEnabledFile)) > $trafficLimitPeriod) {   // Time to remove the limit
            pmssTrafficLimitThrottleLiftAfterCooldown($thisUser, $userTrafficLimitEnabledFile, 'traffic throttle removed after cooldown');
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
        echo pmssLogFormatTimestampedLine('FireQOS drift: '.implode(', ', $changes));
        $output = [];
        $rc = 1;
        exec('timeout --kill-after=30 300 /scripts/util/setupNetwork.php >> /var/log/pmss/setupNetwork.log 2>&1', $output, $rc);
        echo pmssLogFormatTimestampedLine("setupNetwork exit {$rc}");
        $contents = @file_get_contents($fireqosPath);
        $verified = $contents === false ? null : networkFireqosRenderedUserCaps($contents);
        echo pmssLogFormatTimestampedLine(networkFireqosNeedsApply($desired, $verified) ? 'verify mismatch' : 'verify ok');
    }
}
