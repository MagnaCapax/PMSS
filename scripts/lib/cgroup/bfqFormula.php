<?php
/**
 * Pure formula for deriving a per-user blkio.bfq.weight from configured RAM.
 *
 * Extracted as a lib so cgroupBfqWeightApplyTest.php can exercise the math
 * without invoking the cron's pre-flight I/O (root, /sys/fs/cgroup/blkio,
 * BFQ scheduler) checks. Per AGENTS.md hermetic-test doctrine.
 *
 * Curve: round(k * sqrt(ramMiB)) clamped to [1, customerMax].
 * With k=3.535 and customerMax=700, the curve hits ~640 at 32 GiB,
 * ~447 at 16 GiB, ~316 at 8 GiB, ~111 at 1 GiB, 55 at 250 MiB.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

declare(strict_types=1);

/**
 * Derive a customer-tier-clamped bfq.weight value from a memory baseline.
 *
 * @param int   $memoryMiB        Per-user RAM allocation in MiB.
 * @param float $coefficient      Sqrt multiplier (default 3.535 per operator).
 * @param int   $customerMax      Customer-reachable ceiling (default 700).
 *
 * @return int Clamped weight in [1, customerMax].
 */
function pmssBfqFormulaWeight(int $memoryMiB, float $coefficient = 3.535, int $customerMax = 700): int
{
    if ($memoryMiB <= 0) {
        return 1;
    }
    if ($customerMax < 1) {
        $customerMax = 1;
    }
    $derived = (int) round($coefficient * sqrt($memoryMiB));
    if ($derived < 1) {
        return 1;
    }
    if ($derived > $customerMax) {
        return $customerMax;
    }
    return $derived;
}
