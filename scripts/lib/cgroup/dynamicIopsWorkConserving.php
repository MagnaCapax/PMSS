<?php
/** Pure demand-limited weighted allocation (ADR 0256). @license GPL-3.0-only */

/** Split a non-negative finite pool over non-negative weights and demands (INF is unbounded). */
function pmssDynamicIopsWaterFill(array $weights, array $demand, float $pool): array
{
    $allocation = array_fill_keys(array_keys($weights), 0.0);
    // A zero share cannot claim capacity, even after every positive share has been satisfied.
    $weights = array_filter($weights, static function ($weight): bool { return $weight > 0; });
    while ($weights !== []) {
        $sum = array_sum($weights);
        $satisfied = [];
        // Compare every account against the same round's pool before reclaiming spare capacity.
        foreach ($weights as $user => $weight) {
            if ($demand[$user] <= $pool * ($weight / $sum)) $satisfied[] = $user;
        }
        if ($satisfied === []) {
            foreach ($weights as $user => $weight) $allocation[$user] = $pool * ($weight / $sum);
            break;
        }
        foreach ($satisfied as $user) {
            $allocation[$user] = (float) $demand[$user];
            $pool = max(0.0, $pool - $allocation[$user]);
            unset($weights[$user]);
        }
    }
    return $allocation;
}

/** Unknown or throttle-limited demand stays unbounded; measured quiet readers retain headroom. */
function pmssDynamicIopsDemand(array $rows, array $rates, ?array $wantsMore, float $headroom): array
{
    $demand = [];
    foreach ($rows as $user => $row) {
        $rate = pmssFiniteNonNegativeFloat($rates[$user] ?? null);
        $demand[$user] = $rate === null || ($wantsMore[$user] ?? false) === true
            ? INF : max((float) $row['floor'], $rate * $headroom);
    }
    return $demand;
}
