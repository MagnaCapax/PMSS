<?php
/**
 * Shared trust check for root-produced per-user artifacts (enforcement markers).
 *
 * A value that affects accounting, quotas, or limits (ADR-0046 "Enforced" class) may be
 * honored ONLY when its file is root-owned and not tenant-writable. The tenant's home is
 * their own (mode 0770), so they can create/replace files there; a reader that trusts a
 * user-owned marker lets the account holder influence how their own limits are enforced.
 *
 * This is the single source of truth for that check. usageAlerts.php already implemented it
 * (pmssUsageAlertsRootArtifactIsTrusted); the enforcement crons (trafficLimits.php for
 * .bonusTraffic, cgroupBfqWeightApply.php for .bonus) read the SAME files and MUST apply the
 * SAME rule — decision-DRY, so the trust rule cannot drift between readers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (!function_exists('pmssRootArtifactMetadataIsTrusted')) {
    /**
     * Trust a stat() tuple only when it is a regular file, root-owned, not world/other-writable,
     * and not group-writable unless the group is root (legacy root:root group-writable artifacts).
     *
     * @param array<string,int> $stat lstat() result
     */
    function pmssRootArtifactMetadataIsTrusted(array $stat): bool
    {
        $mode = (int) ($stat['mode'] ?? 0);
        return ($mode & 0170000) === 0100000
            && (int) ($stat['uid'] ?? -1) === 0
            && ($mode & 0002) === 0
            && (($mode & 0020) === 0 || (int) ($stat['gid'] ?? -1) === 0);
    }
}

if (!function_exists('pmssRootArtifactIsTrusted')) {
    /** True when $path is a root-owned, tenant-non-writable regular file (lstat: refuses symlinks). */
    function pmssRootArtifactIsTrusted(string $path): bool
    {
        $stat = @lstat($path);
        return is_array($stat) && pmssRootArtifactMetadataIsTrusted($stat);
    }
}
