<?php
/** Traffic overage tier defaults, normalization and selection.
 * @license GPL-3.0-only
 * @author PMSS Team
 */
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
 * Normalize operator-provided overage stages, dropping malformed rows.
 *
 * Returned rows are already ordered for enforcement: highest overage first,
 * then highest GiB threshold, then original operator order for exact ties.
 *
 * @param array<int, array<string, float|int|string|bool|array|object>> $rawStages
 * @return array<int, array{overagePercent:float,minOverageGiB:float,capMbit:int,index:int}>
 */
function pmssTrafficLimitNormalizeOverageStages(array $rawStages): array
{
    $normalizedStages = [];
    foreach ($rawStages as $index => $stage) {
        if (!is_array($stage) || !is_numeric($stage['overagePercent'] ?? null) || !is_numeric($stage['capMbit'] ?? null)) {
            continue;
        }

        $stageCapMbit = (int) $stage['capMbit'];
        if ($stageCapMbit <= 0) {
            continue;
        }

        $stageMinOverageGiB = is_numeric($stage['minOverageGiB'] ?? null)
            ? max(0.0, (float) $stage['minOverageGiB'])
            : 0.0;

        $normalizedStages[] = ['overagePercent' => max(0.0, (float) $stage['overagePercent']), 'minOverageGiB' => $stageMinOverageGiB, 'capMbit' => $stageCapMbit, 'index' => (int) $index];
    }

    usort($normalizedStages, static function (array $left, array $right): int {
        return ($right['overagePercent'] <=> $left['overagePercent'])
            ?: ($right['minOverageGiB'] <=> $left['minOverageGiB'])
            ?: ($left['index'] <=> $right['index']);
    });

    return $normalizedStages;
}

/** @param array<int, array<string, float|int|string|bool|array|object>> $rawStages */
function pmssTrafficLimitOverageStagesMatchLegacyDefault(array $rawStages): bool
{
    $candidate = pmssTrafficLimitNormalizeOverageStages($rawStages);
    $legacy = pmssTrafficLimitNormalizeOverageStages(pmssTrafficLimitLegacyOverageStages());

    $stripIndex = static function (array $stage): array {
        unset($stage['index']);
        return $stage;
    };

    return array_map($stripIndex, $candidate) === array_map($stripIndex, $legacy);
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

    foreach (pmssTrafficLimitNormalizeOverageStages($rawStages) as $stage) {
        if ($overagePercent < $stage['overagePercent'] || $overageGiB < $stage['minOverageGiB']) continue;

        unset($stage['index']);

        return ['effective' => min($postCapMbit, $stage['capMbit']), 'matched' => $stage];
    }

    return ['effective' => $postCapMbit, 'matched' => null];
}
