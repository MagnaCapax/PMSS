<?php
/** Progressive cap arithmetic and the ordered throttle decision.
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/tiers.php';

/**
 * Compute progressive post-cap throttling in Mbit based on overage.
 *
 * @param int   $postCapMbit     Base post-cap speed in Mbit.
 * @param float $overagePercent  Percent over the limit (>= 0).
 * @param float $floorPercent    Minimum percent of post-cap speed.
 * @param float $gracePercent    Overage percent before reduction begins.
 * @param int   $minMbit         Absolute minimum in Mbit (FireQOS safety).
 *
 * @return array{effective:int, adjustedOverage:float, floorMbit:int}
 */
function pmssTrafficLimitComputeProgressiveCapMbit(int $postCapMbit, float $overagePercent, float $floorPercent, float $gracePercent, int $minMbit = 1): array
{
    $postCapMbit = max(0, $postCapMbit);
    if ($postCapMbit === 0) return ['effective' => 0, 'adjustedOverage' => 0.0, 'floorMbit' => 0];

    $overagePercent = max(0.0, $overagePercent);
    $gracePercent = max(0.0, $gracePercent);
    $floorPercent = max(0.0, min(100.0, $floorPercent));

    $adjustedOverage = max(0.0, $overagePercent - $gracePercent);

    $floorMbit = (int) ceil($postCapMbit * ($floorPercent / 100));
    $floorMbit = min($postCapMbit, max($floorMbit, max(0, $minMbit)));

    return ['effective' => min($postCapMbit, max((int) floor($postCapMbit * (1 - ($adjustedOverage / 100))), $floorMbit)),
        'adjustedOverage' => $adjustedOverage, 'floorMbit' => $floorMbit];
}

/**
 * Resolve the effective throttle cap and stable user log message.
 *
 * A cap the account's plan sets (`$planCapSet`) outranks the default overage
 * stages and progressive throttle: a plan sold as 1 Gbps after the quota keeps
 * 1000 Mbit (#1056).
 *
 * @param array<int, array<string, float|int|string|bool|array|object>> $overageThrottleStages
 * @return array{effectiveCapMbit:int,logMessage:string}
 */
function pmssTrafficLimitThrottlePlan(float $trafficLimitGiB, float $trafficUsageGiB, int $trafficCapMbit, array $overageThrottleStages, bool $progressiveThrottleEnabled, float $progressiveThrottleFloorPercent, float $progressiveThrottleGracePercent, bool $planCapSet = false): array
{
    $overageGiB = $trafficUsageGiB - $trafficLimitGiB;
    $overagePercent = ($trafficLimitGiB > 0) ? ($overageGiB / $trafficLimitGiB) * 100 : 0.0;
    $effectiveCapMbit = $trafficCapMbit;
    $action = 'enabled';
    $details = sprintf('limit=%.2f GiB usage=%.2f GiB', $trafficLimitGiB, $trafficUsageGiB);
    if ($planCapSet) {
        $details .= sprintf(' plan cap=%d Mbit', $trafficCapMbit);
    } else {
        // Selection accepts an empty catalog; only a matched tier overrides the original cap.
        $tiered = pmssTrafficLimitSelectTieredCapMbit($overagePercent, $overageGiB, $trafficCapMbit, $overageThrottleStages);
        if (is_array($tiered['matched'])) {
            $stage = $tiered['matched'];
            $effectiveCapMbit = $tiered['effective'];
            $action = 'staged';
            $details .= sprintf(
                ' overage=%.1f%% overageGiB=%.2f cap=%d Mbit stageCap=%d Mbit stageOverage=%.1f%% stageMinOverageGiB=%.2f effective=%d Mbit',
                $overagePercent, $overageGiB, $trafficCapMbit, $stage['capMbit'],
                $stage['overagePercent'], $stage['minOverageGiB'], $effectiveCapMbit
            );
        } elseif ($progressiveThrottleEnabled) {
            $progressive = pmssTrafficLimitComputeProgressiveCapMbit($trafficCapMbit, $overagePercent, $progressiveThrottleFloorPercent, $progressiveThrottleGracePercent, 1);
            $effectiveCapMbit = $progressive['effective'];
            $details .= sprintf(
                ' overage=%.1f%% adjusted=%.1f%% cap=%d Mbit effective=%d Mbit floor=%d Mbit',
                $overagePercent, $progressive['adjustedOverage'], $trafficCapMbit,
                $effectiveCapMbit, $progressive['floorMbit']
            );
        }
    }
    return ['effectiveCapMbit' => $effectiveCapMbit, 'logMessage' => "traffic throttle {$action} ({$details})"];
}
