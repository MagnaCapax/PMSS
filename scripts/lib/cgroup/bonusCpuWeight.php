<?php
/**
 * Free Bonus Disk percent -> per-user CPU priority (systemd CPUWeight).
 *
 * The provisioning backend writes the account's applied bonus percent into ~/.bonus through
 * writeHomeMarker.php (root-owned, ADR-0046 "enforced" class). cgroupBfqWeightApply.php already
 * raises disk priority from it; cgroupBonusCpuWeightApply.php raises CPU priority the same way.
 * The account never drops below the CPU weight its plan (plus bought extras) gives it.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/policy.php';
require_once __DIR__.'/Manager.php';
require_once dirname(__DIR__).'/runtime/filesystem.php';
require_once dirname(__DIR__).'/user/identity.php';
require_once dirname(__DIR__).'/user/rootArtifactTrust.php';

const PMSS_CPU_WEIGHT_MAX = 10000; // systemd CPUWeight upper bound.

/**
 * Bonus percent from the account's ~/.bonus marker, or 0.
 *
 * Only a root-owned, tenant-non-writable regular file counts: the home directory belongs to the
 * account, so any file the account could have written there is ignored (rootArtifactTrust.php).
 * The opened handle must be the inode that was checked, so a swap between check and read fails.
 */
function pmssUserBonusPercentReadTrusted(string $user, string $homeRoot = '/home'): int
{
    if (!pmssValidateUsername($user)) {
        return 0;
    }
    $metadata = null;
    $raw = pmssRegularFileRead(rtrim($homeRoot, '/').'/'.$user.'/.bonus', 0, 64, $metadata);
    if (!is_string($raw) || !is_array($metadata) || !pmssRootArtifactMetadataIsTrusted($metadata)) {
        return 0;
    }
    $value = trim($raw);
    if (preg_match('/^[0-9]{1,9}$/', $value) !== 1) {
        return 0;
    }
    return min(PMSS_BFQ_FALLBACK_MAX_BONUS_PERCENT, (int) $value);
}

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
