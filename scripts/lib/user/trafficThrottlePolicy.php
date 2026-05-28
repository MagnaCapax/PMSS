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

    $rawEffective = $postCapMbit * (1 - ($adjustedOverage / 100));
    $minMbit = max(0, $minMbit);
    $floorMbit = (int) ceil($postCapMbit * ($floorPercent / 100));
    $floorMbit = min($postCapMbit, max($floorMbit, $minMbit));

    $effective = (int) floor($rawEffective);
    $effective = min($postCapMbit, max($effective, $floorMbit));

    return ['effective' => $effective, 'adjustedOverage' => $adjustedOverage, 'floorMbit' => $floorMbit];
}

/** @return array<int, array<string, float|int>> Default post-cap throttling profile. */
function pmssTrafficLimitDefaultOverageStages(): array
{
    return [['overagePercent' => 0.0, 'minOverageGiB' => 0.0, 'capMbit' => 100]];
}

/** @return array<int, array<string, float|int>> Legacy PMSS-owned tier table. */
function pmssTrafficLimitLegacyOverageStages(): array
{
    return [
        ['overagePercent' => 200.0, 'minOverageGiB' => 0.0,    'capMbit' => 1],
        ['overagePercent' => 125.0, 'minOverageGiB' => 0.0,    'capMbit' => 1],
        ['overagePercent' => 100.0, 'minOverageGiB' => 0.0,    'capMbit' => 10],
        ['overagePercent' => 75.0,  'minOverageGiB' => 5120.0, 'capMbit' => 25],
        ['overagePercent' => 50.0,  'minOverageGiB' => 3072.0, 'capMbit' => 50],
    ];
}

/**
 * Normalize network throttle config for cron enforcement.
 *
 * @param array<string,mixed> $networkConfig
 * @return array{defaultTrafficCapMbit:int,progressiveThrottleEnabled:bool,progressiveThrottleFloorPercent:float,progressiveThrottleGracePercent:float,overageThrottleStages:array<int,array<string,float|int|string|bool|array|object>>}
 */
function pmssTrafficLimitThrottlePolicyFromNetworkConfig(array $networkConfig): array
{
    $throttle = isset($networkConfig['throttle']) && is_array($networkConfig['throttle']) ? $networkConfig['throttle'] : [];
    $capMbit = (isset($throttle['max']) && is_numeric($throttle['max'])) ? (int) $throttle['max'] : 100;
    $progressiveRaw = $throttle['progressiveThrottleEnabled'] ?? true;
    $overageStages = (isset($throttle['overageStages']) && is_array($throttle['overageStages']) &&
        !pmssTrafficLimitOverageStagesMatchLegacyDefault($throttle['overageStages']))
        ? $throttle['overageStages']
        : pmssTrafficLimitDefaultOverageStages();

    return [
        'defaultTrafficCapMbit' => $capMbit > 0 ? $capMbit : 100,
        'progressiveThrottleEnabled' => is_bool($progressiveRaw) ? $progressiveRaw : !in_array(pmssEnvValueNormalized($progressiveRaw), ['0', 'false', 'no', 'off'], true),
        'progressiveThrottleFloorPercent' => max(0.0, min(
            100.0,
            (isset($throttle['progressiveThrottleFloorPercent']) && is_numeric($throttle['progressiveThrottleFloorPercent'])) ? (float) $throttle['progressiveThrottleFloorPercent'] : 2.5
        )),
        'progressiveThrottleGracePercent' => max(
            0.0,
            (isset($throttle['progressiveThrottleGracePercent']) && is_numeric($throttle['progressiveThrottleGracePercent'])) ? (float) $throttle['progressiveThrottleGracePercent'] : 0.0
        ),
        'overageThrottleStages' => $overageStages,
    ];
}

/**
 * Normalize operator-provided overage stages, dropping malformed rows.
 *
 * @param array<int, array<string, float|int|string|bool|array|object>> $rawStages
 * @return array<int, array{overagePercent:float,minOverageGiB:float,capMbit:int,index:int}>
 */
function pmssTrafficLimitNormalizeOverageStages(array $rawStages): array
{
    $normalizedStages = [];
    foreach ($rawStages as $index => $stage) {
        if (!is_array($stage) || !isset($stage['overagePercent']) || !is_numeric($stage['overagePercent']) || !isset($stage['capMbit']) || !is_numeric($stage['capMbit'])) {
            continue;
        }

        $stageCapMbit = (int) $stage['capMbit'];
        if ($stageCapMbit <= 0) {
            continue;
        }

        $stageMinOverageGiB = (isset($stage['minOverageGiB']) && is_numeric($stage['minOverageGiB']))
            ? max(0.0, (float) $stage['minOverageGiB'])
            : 0.0;

        $normalizedStages[] = ['overagePercent' => max(0.0, (float) $stage['overagePercent']), 'minOverageGiB' => $stageMinOverageGiB, 'capMbit' => $stageCapMbit, 'index' => (int) $index];
    }

    return $normalizedStages;
}

/** @param array<int, array{overagePercent:float,minOverageGiB:float,capMbit:int,index:int}> $normalizedStages */
function pmssTrafficLimitSortOverageStages(array &$normalizedStages): void
{
    usort($normalizedStages, static function (array $left, array $right): int {
        return ($right['overagePercent'] <=> $left['overagePercent'])
            ?: ($right['minOverageGiB'] <=> $left['minOverageGiB'])
            ?: ($left['index'] <=> $right['index']);
    });
}

/** @param array<int, array<string, float|int|string|bool|array|object>> $rawStages */
function pmssTrafficLimitOverageStagesMatchLegacyDefault(array $rawStages): bool
{
    $candidate = pmssTrafficLimitNormalizeOverageStages($rawStages);
    $legacy = pmssTrafficLimitNormalizeOverageStages(pmssTrafficLimitLegacyOverageStages());
    pmssTrafficLimitSortOverageStages($candidate);
    pmssTrafficLimitSortOverageStages($legacy);

    $stripIndex = static function (array $stage): array {
        unset($stage['index']);
        return $stage;
    };

    return array_map($stripIndex, $candidate) === array_map($stripIndex, $legacy);
}

/**
 * Resolve the effective throttle cap and stable user log message.
 *
 * @param array<int, array<string, float|int|string|bool|array|object>> $overageThrottleStages
 * @return array{effectiveCapMbit:int,logMessage:string}
 */
function pmssTrafficLimitThrottlePlan(float $trafficLimitGiB, float $trafficUsageGiB, int $trafficCapMbit, array $overageThrottleStages, bool $progressiveThrottleEnabled, float $progressiveThrottleFloorPercent, float $progressiveThrottleGracePercent): array
{
    $overageGiB = $trafficUsageGiB - $trafficLimitGiB;
    $overagePercent = ($trafficLimitGiB > 0) ? ($overageGiB / $trafficLimitGiB) * 100 : 0.0;
    $tiered = !empty($overageThrottleStages)
        ? pmssTrafficLimitSelectTieredCapMbit($overagePercent, $overageGiB, $trafficCapMbit, $overageThrottleStages)
        : ['effective' => (int) $trafficCapMbit, 'matched' => null];

    if (is_array($tiered['matched'])) {
        $stage = $tiered['matched'];
        return [
            'effectiveCapMbit' => $tiered['effective'],
            'logMessage' => sprintf(
                'traffic throttle staged (limit=%.2f GiB usage=%.2f GiB overage=%.1f%% overageGiB=%.2f cap=%d Mbit stageCap=%d Mbit stageOverage=%.1f%% stageMinOverageGiB=%.2f effective=%d Mbit)',
                $trafficLimitGiB, $trafficUsageGiB, $overagePercent, $overageGiB,
                $trafficCapMbit, $stage['capMbit'], $stage['overagePercent'],
                $stage['minOverageGiB'], $tiered['effective']
            ),
        ];
    }

    if (!$progressiveThrottleEnabled) {
        return [
            'effectiveCapMbit' => (int) $trafficCapMbit,
            'logMessage' => sprintf('traffic throttle enabled (limit=%.2f GiB usage=%.2f GiB)', $trafficLimitGiB, $trafficUsageGiB),
        ];
    }

    $progressive = pmssTrafficLimitComputeProgressiveCapMbit($trafficCapMbit, $overagePercent, $progressiveThrottleFloorPercent, $progressiveThrottleGracePercent, 1);

    return [
        'effectiveCapMbit' => $progressive['effective'],
        'logMessage' => sprintf(
            'traffic throttle enabled (limit=%.2f GiB usage=%.2f GiB overage=%.1f%% adjusted=%.1f%% cap=%d Mbit effective=%d Mbit floor=%d Mbit)',
            $trafficLimitGiB, $trafficUsageGiB, $overagePercent, $progressive['adjustedOverage'],
            $trafficCapMbit, $progressive['effective'], $progressive['floorMbit']
        ),
    ];
}

/**
 * Resolve the active tiered cap from overage stages.
 *
 * Invalid stage entries are ignored so malformed operator overrides do not
 * break enforcement for valid entries.
 *
 * @param float                                                          $overagePercent
 * @param float                                                          $overageGiB
 * @param int                                                            $postCapMbit
 * @param array<int, array<string, float|int|string|bool|array|object>>  $rawStages
 *
 * @return array{effective:int, matched:array<string, float|int>|null}
 */
function pmssTrafficLimitSelectTieredCapMbit(float $overagePercent, float $overageGiB, int $postCapMbit, array $rawStages): array
{
    $postCapMbit = max(0, $postCapMbit);
    if ($postCapMbit === 0) return ['effective' => 0, 'matched' => null];

    $overagePercent = max(0.0, $overagePercent);
    $overageGiB = max(0.0, $overageGiB);

    $normalizedStages = pmssTrafficLimitNormalizeOverageStages($rawStages);
    pmssTrafficLimitSortOverageStages($normalizedStages);

    foreach ($normalizedStages as $stage) {
        if ($overagePercent < $stage['overagePercent'] || $overageGiB < $stage['minOverageGiB']) continue;

        $effectiveCapMbit = min($postCapMbit, (int) $stage['capMbit']);
        unset($stage['index']);

        return ['effective' => $effectiveCapMbit, 'matched' => $stage];
    }

    return ['effective' => $postCapMbit, 'matched' => null];
}
