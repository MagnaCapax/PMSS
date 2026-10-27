<?php
/**
 * Traffic throttle policy helpers for quota enforcement.
 *
 * The traffic-limit CLI owns persistence in trafficLimit.php; cron enforcement
 * depends on this file for deterministic cap selection and log text.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime.php';
require_once __DIR__.'/trafficThrottle/tiers.php';
require_once __DIR__.'/trafficThrottle/plan.php';

/**
 * Normalize network throttle config for cron enforcement.
 *
 * @param array<string,mixed> $networkConfig
 * @return array{defaultTrafficCapMbit:int,progressiveThrottleEnabled:bool,progressiveThrottleFloorPercent:float,progressiveThrottleGracePercent:float,overageThrottleStages:array<int,array<string,float|int|string|bool|array|object>>}
 */
function pmssTrafficLimitThrottlePolicyFromNetworkConfig(array $networkConfig): array
{
    $throttle = is_array($networkConfig['throttle'] ?? null) ? $networkConfig['throttle'] : [];
    $capMbit = is_numeric($throttle['max'] ?? null) ? (int) $throttle['max'] : 100;
    $progressiveRaw = $throttle['progressiveThrottleEnabled'] ?? true;
    $overageStages = (is_array($throttle['overageStages'] ?? null) &&
        !pmssTrafficLimitOverageStagesMatchLegacyDefault($throttle['overageStages']))
        ? $throttle['overageStages']
        : pmssTrafficLimitDefaultOverageStages();

    return [
        'defaultTrafficCapMbit' => $capMbit > 0 ? $capMbit : 100,
        'progressiveThrottleEnabled' => is_bool($progressiveRaw) ? $progressiveRaw : !in_array(pmssEnvValueNormalized($progressiveRaw), ['0', 'false', 'no', 'off'], true),
        'progressiveThrottleFloorPercent' => max(0.0, min(
            100.0,
            is_numeric($throttle['progressiveThrottleFloorPercent'] ?? null) ? (float) $throttle['progressiveThrottleFloorPercent'] : 2.5
        )),
        'progressiveThrottleGracePercent' => max(
            0.0,
            is_numeric($throttle['progressiveThrottleGracePercent'] ?? null) ? (float) $throttle['progressiveThrottleGracePercent'] : 0.0
        ),
        'overageThrottleStages' => $overageStages,
    ];
}

/**
 * Return the post-quota cap the account's plan sets, or 0 when it sets none.
 *
 * @param mixed $userConfig
 */
function pmssTrafficLimitPlanCapMbit($userConfig): int
{
    if (!is_array($userConfig) || !isset($userConfig['trafficCapMbit']) || !is_numeric($userConfig['trafficCapMbit'])) return 0;
    return max(0, (int) $userConfig['trafficCapMbit']);
}
