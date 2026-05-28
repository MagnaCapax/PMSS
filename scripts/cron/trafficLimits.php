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
require_once '/scripts/lib/user/selection.php';
require_once '/scripts/lib/user/userConfigStore.php';
require_once __DIR__.'/../lib/user/trafficLimit.php';
if (!pmssDirEnsureExists('/var/run/pmss/trafficLimits', 0755)) {
    fwrite(STDERR, "Unable to prepare traffic limit runtime directory\n");
    exit(1);
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
    $userTrafficLimitEnabledFile = "/var/run/pmss/trafficLimits/{$thisUser}.enabled";
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
        setRateLimit($thisUser, $throttlePlan['effectiveCapMbit']);    // Apply rate limiting
        pmssUserLog($thisUser, $throttlePlan['logMessage']);

    } else if (file_exists($userTrafficLimitEnabledFile)) {     // Now let's see if it's time to remove it?

        if ((time() - (int) filemtime($userTrafficLimitEnabledFile)) > $trafficLimitPeriod) {   // Time to remove the limit
            if (!pmssTrafficLimitMarkerRemove($thisUser, $userTrafficLimitEnabledFile)) {
                continue;
            }
            pmssUserLog($thisUser, 'traffic throttle removed after cooldown');
            setRateLimit($thisUser, $trafficCapMbit, false);
			// Do it second time as removal does not always work for some reason
			sleep(1);
			setRateLimit($thisUser, $trafficCapMbit, false);
        }

    }
}

/**
 * Emit traffic-limit safety warnings to cron output and the per-user log.
 */
function pmssTrafficLimitLog(string $user, string $message): void
{
    echo date('Y-m-d H:i:s') . ": {$message}\n";
    if (pmssValidateUsername($user)) {
        pmssUserLog($user, $message);
    }
}

/**
 * Refresh the active-throttle marker and report marker write failures.
 */
function pmssTrafficLimitMarkerTouch(string $user, string $path): bool
{
    if (!@touch($path)) {
        pmssTrafficLimitLog($user, "traffic throttle marker touch failed ({$path})");
        return false;
    }

    if (!@chmod($path, 0600)) {
        pmssTrafficLimitLog($user, "traffic throttle marker chmod failed ({$path})");
    }

    return true;
}

/**
 * Remove the active-throttle marker only when filesystem state confirms it.
 */
function pmssTrafficLimitMarkerRemove(string $user, string $path): bool
{
    if (!file_exists($path) || @unlink($path) || !file_exists($path)) {
        return true;
    }

    pmssTrafficLimitLog($user, "traffic throttle marker removal failed ({$path})");
    return false;
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
    if (!is_dir($home) || is_link($home) || @realpath($home) !== $home) return null;

    $path = $home.'/.throttle';
    return pmssUserFilePathIsSafe($path) ? $path : null;
}

function setRateLimit($user, $trafficCapMbit, $enable=true) {
    $throttleFile = pmssTrafficLimitThrottleFilePath((string) $user);
    if ($throttleFile === null) return;

    $error = null;
    if ($enable == false) {
        if (!pmssTrafficLimitThrottleFileRemove($throttleFile, $error)) {
            pmssTrafficLimitLog((string) $user, 'traffic throttle file removal failed ('.($error ?: $throttleFile).')');
        }
        return;
    }

    if (!pmssTrafficLimitThrottleFileWrite($throttleFile, (int) $trafficCapMbit, $error)) {
        pmssTrafficLimitLog((string) $user, 'traffic throttle file write failed ('.($error ?: $throttleFile).')');
    }
}
