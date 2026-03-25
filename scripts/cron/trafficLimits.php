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
require_once '/scripts/lib/rtorrentConfig.php';
require_once '/scripts/lib/network/config.php';
require_once '/scripts/lib/userLifecycle.php';
require_once '/scripts/lib/user/userConfigStore.php';
foreach ([
    '/scripts/lib/user/trafficLimit.php',
    __DIR__.'/../lib/user/log.php',
] as $dependency) {
    if (is_file($dependency)) {
        require_once $dependency;
    }
}
if (!file_exists('/var/run/pmss/trafficLimits')) `mkdir -p /var/run/pmss/trafficLimits`;

$trafficLimitPeriod = 3 * 24 * 60 * 60;     // 3 days limiting period

$users = pmssListManagedUsers('/scripts/listUsers.php');
if (count($users) == 0) die("No users in this system!\n");

$networkConfig = networkLoadConfig();
$defaultTrafficCapMbit = 100;
if (isset($networkConfig['throttle']['max']) && is_numeric($networkConfig['throttle']['max'])) {
    $defaultTrafficCapMbit = (int) $networkConfig['throttle']['max'];
}
$defaultTrafficCapMbit = ($defaultTrafficCapMbit > 0) ? $defaultTrafficCapMbit : 100;
$slidingThrottleStart = 75.0;
if (isset($networkConfig['throttle']['slidingThrottleStart']) && is_numeric($networkConfig['throttle']['slidingThrottleStart'])) {
    $slidingThrottleStart = (float) $networkConfig['throttle']['slidingThrottleStart'];
}
$slidingThrottleStart = max(0.0, min(100.0, $slidingThrottleStart));
$networkSpeedMbit = 1000;
if (isset($networkConfig['speed']) && is_numeric($networkConfig['speed'])) {
    $networkSpeedMbit = (int) $networkConfig['speed'];
}
$networkSpeedMbit = ($networkSpeedMbit > 0) ? $networkSpeedMbit : 1000;
$progressiveThrottleEnabled = true;
if (isset($networkConfig['throttle']['progressiveThrottleEnabled'])) {
    $raw = $networkConfig['throttle']['progressiveThrottleEnabled'];
    if (is_bool($raw)) {
        $progressiveThrottleEnabled = $raw;
    } elseif (is_numeric($raw)) {
        $progressiveThrottleEnabled = ((int) $raw) !== 0;
    } elseif (is_string($raw)) {
        $value = strtolower(trim($raw));
        $progressiveThrottleEnabled = !in_array($value, ['0', 'false', 'no', 'off'], true);
    }
}
$progressiveThrottleFloorPercent = 2.5;
if (isset($networkConfig['throttle']['progressiveThrottleFloorPercent']) &&
    is_numeric($networkConfig['throttle']['progressiveThrottleFloorPercent'])) {
    $progressiveThrottleFloorPercent = (float) $networkConfig['throttle']['progressiveThrottleFloorPercent'];
}
$progressiveThrottleFloorPercent = max(0.0, min(100.0, $progressiveThrottleFloorPercent));
$progressiveThrottleGracePercent = 0.0;
if (isset($networkConfig['throttle']['progressiveThrottleGracePercent']) &&
    is_numeric($networkConfig['throttle']['progressiveThrottleGracePercent'])) {
    $progressiveThrottleGracePercent = (float) $networkConfig['throttle']['progressiveThrottleGracePercent'];
}
$progressiveThrottleGracePercent = max(0.0, $progressiveThrottleGracePercent);
$overageThrottleStages = pmssTrafficLimitDefaultOverageStages();
if (isset($networkConfig['throttle']['overageStages']) && is_array($networkConfig['throttle']['overageStages'])) {
    $overageThrottleStages = $networkConfig['throttle']['overageStages'];
}
$userConfigStore = new UserConfigStore();

foreach($users AS $thisUser) {
    $userTrafficLimitFile = "/etc/seedbox/runtime/trafficLimits/{$thisUser}";
    $trafficDataFile = "/home/{$thisUser}/.trafficData";
    if (!file_exists($trafficDataFile) or
        !file_exists($userTrafficLimitFile) ) continue;

    $data = pmssReadTrafficData($trafficDataFile, $thisUser);
    if ($data === null) {
        echo date('Y-m-d H:i:s') . ": Skipping {$thisUser}, invalid traffic data file\n";
        continue;
    }
    $trafficLimitRaw = trim((string) @file_get_contents($userTrafficLimitFile));
    if ($trafficLimitRaw === '' || !is_numeric($trafficLimitRaw) || (float) $trafficLimitRaw <= 0) {
        continue;
    }
    $trafficLimit = (float) $trafficLimitRaw;
    $bonusTrafficFile = "/home/{$thisUser}/.bonusTraffic";
    $bonusTraffic = pmssTrafficLimitReadGiBFile($bonusTrafficFile);
//    var_dump($data);
    $trafficUsageGiB = ($data['raw']['month'] / 1024);   // Set to GiB
    $trafficLimit += $bonusTraffic;
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
    $slidingThrottleFile = "/var/run/pmss/trafficLimits/{$thisUser}.throttle_mbit";
    $overLimit = ($trafficUsageGiB > $trafficLimit);
    $slidingApplied = false;

    // Apply sliding throttle before the hard limit, unless cooldown is active.
    if (!$overLimit && $slidingThrottleStart < 100) {
        $usagePct = ($trafficLimit > 0)
            ? ($trafficUsageGiB / $trafficLimit) * 100
            : 0.0;
        if ($usagePct >= $slidingThrottleStart && !file_exists($userTrafficLimitEnabledFile)) {
            $range = 100 - $slidingThrottleStart;
            if ($range > 0) {
                $capPct = min(75, (($usagePct - $slidingThrottleStart) / $range) * 75);
                if ($capPct > 0) {
                    $slidingApplied = true;
                    $hardCapMbit = max(0, (int) $trafficCapMbit);
                    $usableBw = max(0, $networkSpeedMbit - $hardCapMbit);
                    $effectiveCeil = $networkSpeedMbit - ($usableBw * ($capPct / 100));
                    $effectiveCeilMbit = max(1, (int) round($effectiveCeil));
                    $previousCeil = null;
                    if (is_file($slidingThrottleFile) && !is_link($slidingThrottleFile)) {
                        $raw = trim((string) @file_get_contents($slidingThrottleFile));
                        if ($raw !== '' && is_numeric($raw)) {
                            $previousCeil = (int) $raw;
                        }
                    }
                    if ($previousCeil === null || $previousCeil !== $effectiveCeilMbit) {
                        if (@file_put_contents($slidingThrottleFile, (string) $effectiveCeilMbit) !== false) {
                            @chmod($slidingThrottleFile, 0600);
                            if (function_exists('pmssUserLog')) {
                                $action = ($previousCeil === null) ? 'applied' : 'updated';
                                pmssUserLog(
                                    $thisUser,
                                    sprintf(
                                        'traffic throttle sliding %s (ceil=%d Mbit usage=%.1f%% limit=%.2f GiB)',
                                        $action,
                                        $effectiveCeilMbit,
                                        $usagePct,
                                        $trafficLimit
                                    )
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    if (!$slidingApplied && is_file($slidingThrottleFile) && !is_link($slidingThrottleFile)) {
        if (@unlink($slidingThrottleFile)) {
            if (function_exists('pmssUserLog')) {
                pmssUserLog($thisUser, 'traffic throttle sliding removed');
            }
        }
    }

    // Needs to stay within the limit for X period of time, hence we can always touch & update the limit file
    if ($overLimit) { // Should be limited

        $effectiveCapMbit = (int) $trafficCapMbit;
        $overageGiB = ($trafficUsageGiB > $trafficLimit)
            ? ($trafficUsageGiB - $trafficLimit)
            : 0.0;
        $overagePercent = ($trafficLimit > 0)
            ? ($overageGiB / $trafficLimit) * 100
            : 0.0;
        $adjustedOverage = 0.0;
        $floorMbit = 0;
        $matchedOverageStage = null;
        if (function_exists('pmssTrafficLimitSelectTieredCapMbit') && !empty($overageThrottleStages)) {
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
            $progressiveThrottleEnabled &&
            function_exists('pmssTrafficLimitComputeProgressiveCapMbit')) {
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
        setRatelimit($thisUser, $effectiveCapMbit);    // Apply rate limiting
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
            } elseif ($progressiveThrottleEnabled && function_exists('pmssTrafficLimitComputeProgressiveCapMbit')) {
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
 * Safely read traffic data snapshot for a user, enforcing ownership and structure.
 */
function pmssReadTrafficData(string $path, string $username): ?array
{
    if (is_link($path)) {
        return null;
    }

    $stats = @stat($path);
    if ($stats === false || $stats['uid'] !== 0) {
        return null;
    }

    $mode = $stats['mode'] & 0777;
    if (($mode & 0022) !== 0) { // group/other writable
        return null;
    }

    $group = @posix_getgrgid($stats['gid']);
    if ($group !== false && $group['name'] !== $username && $group['name'] !== 'root') {
        return null;
    }

    $blob = @file_get_contents($path);
    if ($blob === false || $blob === '') {
        return null;
    }

    $data = @unserialize($blob, ['allowed_classes' => false]);
    if (!is_array($data) || !isset($data['raw']) || !is_array($data['raw'])) {
        return null;
    }

    if (!isset($data['raw']['month']) || !is_numeric($data['raw']['month'])) {
        return null;
    }

    return $data;
}

function setRateLimit($user, $trafficCapMbit, $enable=true) {
    if ($enable == false) { @unlink("/home/{$user}/.throttle"); return; }

    file_put_contents("/home/{$user}/.throttle", (int) $trafficCapMbit);
}
