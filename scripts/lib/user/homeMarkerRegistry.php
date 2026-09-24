<?php
/**
 * ADR-0046 registry for provisioned per-user home marker files: the single source of truth
 * mapping each managed marker to its ownership/enforcement class and its mode.
 *
 * ADR-0046 ("Provisioned in-home file ownership and enforcement classes") defines three classes
 * and defers the concrete registry to "a separate implementation task". This is that registry,
 * scoped to the scalar-integer markers the provisioning backend writes into user homes:
 *
 *   Enforced      root-owned, group=user read only, no user/other write. Value feeds a
 *                 tamper-sensitive control (traffic cap, BFQ I/O weight). Readers additionally
 *                 verify root ownership (scripts/lib/user/rootArtifactTrust.php).
 *   Authoritative root-owned; PMSS owns the value; user may read via group where required.
 *   Display       root-owned; panel-visible only; forging affects the tenant's own dashboard,
 *                 not enforcement.
 *
 * Owner is always root; group is the user (the read channel). Consumers:
 *   - scripts/util/writeHomeMarker.php (the authoritative writer) reads mode+class here.
 *   - future: userPermissions.php repair + setupPermissions.php may consult the class, but they
 *     MUST NOT convert a tenant-owned marker to root (that would bless forged content — #726);
 *     only the authoritative writer creates a marker root-owned.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (!function_exists('pmssHomeMarkerRegistry')) {
    /**
     * @return array<string,array{class:string,mode:int}> keyed by bare marker filename
     */
    function pmssHomeMarkerRegistry(): array
    {
        return [
            '.bonus'        => ['class' => 'enforced',      'mode' => 0640],
            '.bonusTraffic' => ['class' => 'enforced',      'mode' => 0640],
            '.billingId'    => ['class' => 'authoritative', 'mode' => 0640],
            '.bonusQuota'   => ['class' => 'display',        'mode' => 0644],
            '.trafficLimit' => ['class' => 'display',        'mode' => 0644],
        ];
    }
}

if (!function_exists('pmssHomeMarkerIsKnown')) {
    function pmssHomeMarkerIsKnown(string $marker): bool
    {
        return array_key_exists($marker, pmssHomeMarkerRegistry());
    }
}

if (!function_exists('pmssHomeMarkerMode')) {
    /** Mode for a known marker, or null when the marker is not in the registry. */
    function pmssHomeMarkerMode(string $marker): ?int
    {
        $reg = pmssHomeMarkerRegistry();
        return array_key_exists($marker, $reg) ? (int) $reg[$marker]['mode'] : null;
    }
}

if (!function_exists('pmssHomeMarkerClass')) {
    /** ADR-0046 class for a known marker, or null when not in the registry. */
    function pmssHomeMarkerClass(string $marker): ?string
    {
        $reg = pmssHomeMarkerRegistry();
        return array_key_exists($marker, $reg) ? (string) $reg[$marker]['class'] : null;
    }
}
