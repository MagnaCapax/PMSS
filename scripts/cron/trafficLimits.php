#!/usr/bin/env php
<?php
/**
 * Traffic limits enforcement cron.
 *
 * Monitors per-user traffic usage and applies/removes throttling based on
 * configured limits. Throttling is applied when usage exceeds limit and
 * removed after a cooldown period (3 days by default).
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
require_once '/scripts/lib/network/config.php';
require_once '/scripts/lib/traffic/storage.php';
require_once '/scripts/lib/userLifecycle.php';
require_once '/scripts/lib/user/userConfigStore.php';
require_once __DIR__.'/../lib/user/trafficLimit.php';
$userLogDependency = __DIR__.'/../lib/user/log.php';
if (is_file($userLogDependency)) {
    require_once $userLogDependency;
}
if (!pmssDirEnsureExists('/var/run/pmss/trafficLimits', 0755)) {
    fwrite(STDERR, "Unable to prepare traffic limit runtime directory\n");
    exit(1);
}

$trafficLimitPeriod = 3 * 24 * 60 * 60;     // 3 days limiting period

$users = pmssListManagedUsers('/scripts/listUsers.php');
if (count($users) == 0) die("No users in this system!\n");

$networkConfig = networkLoadConfig();
$defaultTrafficCapMbit = (isset($networkConfig['throttle']['max']) && is_numeric($networkConfig['throttle']['max']))
    ? (int) $networkConfig['throttle']['max']
    : 100;
$defaultTrafficCapMbit = ($defaultTrafficCapMbit > 0) ? $defaultTrafficCapMbit : 100;
$progressiveThrottleRaw = $networkConfig['throttle']['progressiveThrottleEnabled'] ?? true;
$progressiveThrottleEnabled = is_bool($progressiveThrottleRaw)
    ? $progressiveThrottleRaw
    : !in_array(pmssEnvValueNormalized($progressiveThrottleRaw), ['0', 'false', 'no', 'off'], true);
$progressiveThrottleFloorPercent = max(0.0, min(
    100.0,
    (isset($networkConfig['throttle']['progressiveThrottleFloorPercent']) && is_numeric($networkConfig['throttle']['progressiveThrottleFloorPercent']))
        ? (float) $networkConfig['throttle']['progressiveThrottleFloorPercent']
        : 2.5
));
$progressiveThrottleGracePercent = max(
    0.0,
    (isset($networkConfig['throttle']['progressiveThrottleGracePercent']) && is_numeric($networkConfig['throttle']['progressiveThrottleGracePercent']))
        ? (float) $networkConfig['throttle']['progressiveThrottleGracePercent']
        : 0.0
);
$overageThrottleStages = pmssTrafficLimitDefaultOverageStages();
if (isset($networkConfig['throttle']['overageStages']) && is_array($networkConfig['throttle']['overageStages'])) {
    $configuredOverageStages = $networkConfig['throttle']['overageStages'];
    if (!pmssTrafficLimitOverageStagesMatchLegacyDefault($configuredOverageStages)) {
        $overageThrottleStages = $configuredOverageStages;
    }
}
$userConfigStore = new UserConfigStore();

foreach($users AS $thisUser) {
    $userTrafficLimitFile = "/etc/seedbox/runtime/trafficLimits/{$thisUser}";
    $trafficDataFile = "/home/{$thisUser}/.trafficData";
    if (!file_exists($trafficDataFile) or
        !file_exists($userTrafficLimitFile) ) continue;

    $data = pmssTrafficReadRootOwnedStatsPayload($trafficDataFile, $thisUser);
    if ($data === null) {
        echo date('Y-m-d H:i:s') . ": Skipping {$thisUser}, invalid traffic data file\n";
        continue;
    }
    $trafficLimitState = pmssTrafficLimitStateRead($userTrafficLimitFile, "/home/{$thisUser}/.bonusTraffic");
    if ($trafficLimitState['effectiveLimitGiB'] <= 0) {
        continue;
    }
    $trafficLimit = (float) $trafficLimitState['effectiveLimitGiB'];
    $trafficUsageGiB = ($data['raw']['month'] / 1024);   // Set to GiB
    $trafficCapMbit = $defaultTrafficCapMbit;
    $userConfig = $userConfigStore->get($thisUser);
    if (is_array($userConfig) && isset($userConfig['trafficCapMbit']) && is_numeric($userConfig['trafficCapMbit'])) {
        $trafficCapMbit = (int) $userConfig['trafficCapMbit'];
    }
    if ($trafficCapMbit <= 0) {
        $trafficCapMbit = $defaultTrafficCapMbit;
    }

    // Enforce traffic throttling: when usage exceeds the configured limit touch the
    // `.enabled` marker (keeping it fresh so sustained overages remain throttled).
    // Once usage drops below the threshold for the configured cooldown window,
    // remove the marker and lift the rate limit. The double disable call guards
    // against occasional router desyncs.
    $userTrafficLimitEnabledFile = "/var/run/pmss/trafficLimits/{$thisUser}.enabled";
    $overLimit = ($trafficUsageGiB > $trafficLimit);

    // Needs to stay within the limit for X period of time, hence we can always touch & update the limit file
    if ($overLimit) {

        $effectiveCapMbit = (int) $trafficCapMbit;
        $overageGiB = $trafficUsageGiB - $trafficLimit;
        $overagePercent = ($trafficLimit > 0)
            ? ($overageGiB / $trafficLimit) * 100
            : 0.0;
        $adjustedOverage = 0.0;
        $floorMbit = 0;
        $matchedOverageStage = null;
        if (!empty($overageThrottleStages)) {
            $tiered = pmssTrafficLimitSelectTieredCapMbit(
                $overagePercent,
                $overageGiB,
                $trafficCapMbit,
                $overageThrottleStages
            );
            $effectiveCapMbit = $tiered['effective'];
            $matchedOverageStage = $tiered['matched'];
        }
        if ($matchedOverageStage === null &&
            $progressiveThrottleEnabled) {
            $progressive = pmssTrafficLimitComputeProgressiveCapMbit(
                $trafficCapMbit,
                $overagePercent,
                $progressiveThrottleFloorPercent,
                $progressiveThrottleGracePercent,
                1
            );
            $effectiveCapMbit = $progressive['effective'];
            $adjustedOverage = $progressive['adjustedOverage'];
            $floorMbit = $progressive['floorMbit'];
        }

        touch( $userTrafficLimitEnabledFile );

        chmod( $userTrafficLimitEnabledFile, 0600);
        setRateLimit($thisUser, $effectiveCapMbit);    // Apply rate limiting
        if (function_exists('pmssUserLog')) {
            if (is_array($matchedOverageStage)) {
                pmssUserLog(
                    $thisUser,
                    sprintf(
                        'traffic throttle staged (limit=%.2f GiB usage=%.2f GiB overage=%.1f%% overageGiB=%.2f cap=%d Mbit stageCap=%d Mbit stageOverage=%.1f%% stageMinOverageGiB=%.2f effective=%d Mbit)',
                        $trafficLimit,
                        $trafficUsageGiB,
                        $overagePercent,
                        $overageGiB,
                        $trafficCapMbit,
                        $matchedOverageStage['capMbit'],
                        $matchedOverageStage['overagePercent'],
                        $matchedOverageStage['minOverageGiB'],
                        $effectiveCapMbit
                    )
                );
            } elseif ($progressiveThrottleEnabled) {
                pmssUserLog(
                    $thisUser,
                    sprintf(
                        'traffic throttle enabled (limit=%.2f GiB usage=%.2f GiB overage=%.1f%% adjusted=%.1f%% cap=%d Mbit effective=%d Mbit floor=%d Mbit)',
                        $trafficLimit,
                        $trafficUsageGiB,
                        $overagePercent,
                        $adjustedOverage,
                        $trafficCapMbit,
                        $effectiveCapMbit,
                        $floorMbit
                    )
                );
            } else {
                pmssUserLog(
                    $thisUser,
                    sprintf(
                        'traffic throttle enabled (limit=%.2f GiB usage=%.2f GiB)',
                        $trafficLimit,
                        $trafficUsageGiB
                    )
                );
            }
        }

    } else if (file_exists($userTrafficLimitEnabledFile)) {     // Now let's see if it's time to remove it?

        if ((time() - (int) filemtime($userTrafficLimitEnabledFile)) > $trafficLimitPeriod) {   // Time to remove the limit
            unlink( $userTrafficLimitEnabledFile );
            if (function_exists('pmssUserLog')) {
                pmssUserLog($thisUser, 'traffic throttle removed after cooldown');
            }
            setRateLimit($thisUser, $trafficCapMbit, false);
			// Do it second time as removal does not always work for some reason
			sleep(1);
			setRateLimit($thisUser, $trafficCapMbit, false);
        }

    }
}

/**
 * Resolve the throttle marker path after rechecking the user/home boundary.
 */
function pmssTrafficLimitThrottleFilePath(string $user): ?string
{
    $user = pmssNormalizeUsername($user);
    if (!pmssValidateUsername($user)) {
        return null;
    }

    $home = "/home/{$user}";
    if (!is_dir($home) || is_link($home) || @realpath($home) !== $home) {
        return null;
    }

    $path = $home.'/.throttle';
    return pmssUserFilePathIsSafe($path) ? $path : null;
}

function setRateLimit($user, $trafficCapMbit, $enable=true) {
    $throttleFile = pmssTrafficLimitThrottleFilePath((string) $user);
    if ($throttleFile === null) {
        return;
    }

    if ($enable == false) { @unlink($throttleFile); return; }

    if (@file_put_contents($throttleFile, (int) $trafficCapMbit) !== false) {
        @chmod($throttleFile, 0644);
    }
}
