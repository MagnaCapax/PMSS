<?php
/**
 * Pure formula for deriving a per-user blkio.bfq.weight from configured RAM,
 * with tenure/spend bonus applied as a direct multiplier (same shape as the
 * Free Bonus Disk Policy storage formula).
 *
 * Curve: round(k * sqrt(ramMiB) * (1 + bonusPct/100)) clamped to [1, kernMax].
 * With k=3.535, the RAM-derived base hits ~640 at 32 GiB, ~447 at 16 GiB,
 * ~316 at 8 GiB, ~111 at 1 GiB. The bonusPct multiplier (0-300% from the
 * Free Bonus Disk Policy formula) carries customers into the [701, 1000]
 * band as they accumulate tenure/spend — that band was held open by design.
 *
 * Multiplicative (not additive) is deliberate: additive would over-boost
 * low-RAM tiers (10 + 300 = 31x for a tiny plan) and under-reward high-RAM
 * tiers (700 + 300 = 1.43x). Multiplicative preserves proportional reward
 * across the whole RAM-tier spread, same shape as the storage bonus.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

declare(strict_types=1);

/**
 * Derive a kernel-clamped bfq.weight from RAM baseline plus bonus multiplier.
 *
 * @param int   $memoryMiB   Per-user RAM allocation in MiB.
 * @param float $coefficient Sqrt multiplier (default 3.535 per operator).
 * @param int   $kernMax     Kernel BFQ absolute max (default 1000).
 * @param float $bonusPct    Bonus-disk percent in [0, 300]; multiplies the
 *                           RAM-derived base (same shape as storage bonus).
 *
 * @return int Clamped weight in [1, kernMax].
 */
function pmssBfqFormulaWeight(
    int $memoryMiB,
    float $coefficient = 3.535,
    int $kernMax = 1000,
    float $bonusPct = 0.0
): int {
    if ($memoryMiB <= 0) {
        return 1;
    }
    $kernMax = max(1, $kernMax);
    $bonused = $coefficient * sqrt($memoryMiB) * (1 + max(0.0, $bonusPct) / 100);
    return max(1, min($kernMax, (int) round($bonused)));
}
