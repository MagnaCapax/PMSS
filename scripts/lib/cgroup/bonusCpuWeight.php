<?php
/**
 * Free Bonus Disk percent -> per-user CPU priority (systemd CPUWeight).
 *
 * The provisioning backend writes the account's applied bonus percent into ~/.bonus through
 * writeHomeMarker.php (ADR-0046 "enforced" class; on hosts the file is owned by the account). cgroupBfqWeightApply.php already
 * raises disk priority from it; cgroupBonusCpuWeightApply.php raises CPU priority the same way,
 * through the same reader and the same #945 value clamp (pmssUserBonusPercentRead() in policy.php).
 * The account never drops below the CPU weight its plan (plus bought extras) gives it.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/policy.php';
require_once __DIR__.'/Manager.php';

const PMSS_CPU_WEIGHT_MAX = 10000; // systemd CPUWeight upper bound.

/** The CPU weight userConfig gives the account: explicit CPUWeight, else the RAM-derived default. */
function pmssBonusCpuBaseWeight(array $config): ?int
{
    if (isset($config['CPUWeight']) && is_numeric($config['CPUWeight']) && (int) $config['CPUWeight'] > 0) {
        return min(PMSS_CPU_WEIGHT_MAX, (int) $config['CPUWeight']);
    }
    $ramMiB = isset($config['ramMiB']) && is_numeric($config['ramMiB']) ? (int) $config['ramMiB'] : 0;
    if ($ramMiB <= 0) {
        return null;
    }
    // userConfig passes ramMiB as --memory-high; Manager floors it at 250 MiB before deriving the weight.
    return \PMSS\Cgroup\Manager::calculateWeightFromMemory(max(250, $ramMiB));
}

/** Raise a base CPU weight by a bonus percent; never below the base, never above systemd's maximum. */
function pmssCpuApplyBonusWeight(int $baseWeight, int $bonusPct, int $max = PMSS_CPU_WEIGHT_MAX): int
{
    $base = max(1, $baseWeight);
    $boosted = (int) round($base * (1 + max(0, $bonusPct) / 100));
    return max($base, min($max, $boosted));
}

/**
 * Parse `systemctl show -p ActiveState -p CPUWeight` output.
 *
 * @return array{active:bool,weight:?int}|null null when the output is not systemctl show output
 */
function pmssBonusCpuSliceStateParse(string $output): ?array
{
    $active = null;
    $weight = null;
    foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
        if (strpos($line, 'ActiveState=') === 0) {
            $active = substr($line, 12) === 'active';
        } elseif (strpos($line, 'CPUWeight=') === 0) {
            $value = substr($line, 10);
            $weight = preg_match('/^[0-9]{1,5}$/', $value) === 1 ? (int) $value : null;
        }
    }
    return $active === null ? null : ['active' => $active, 'weight' => $weight];
}
