<?php
/**
 * Helpers for per-user ingress traffic accounting via systemd IPAccounting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../resources/log.php';
require_once __DIR__.'/../systemdSliceProperties.php';

/**
 * Read systemd IPAccounting counters for the user's slice.
 */
function pmssTrafficIngressReadCounters(int $uid): ?array
{
    return pmssReadSystemdIntProperties(sprintf('user-%d.slice', $uid), ['IPIngressBytes' => 'ingress', 'IPEgressBytes' => 'egress']);
}

/**
 * Update the ingress state file and compute the latest delta.
 *
 * @return array{delta: int, previous_ingress: ?int}
 */
function pmssTrafficIngressUpdateState(string $path, array $counters): array
{
    $previousState = pmssJsonFileReadAssoc($path, true) ?? [];
    $currentIngress = (int) $counters['ingress'];
    $previousIngress = isset($previousState['ingress']) ? (int) $previousState['ingress'] : null;
    $state = [
        'ingress' => $currentIngress,
        'egress' => (int) $counters['egress'],
        'ts' => time(),
    ];

    if ($path !== '' && pmssUserFilePathIsSafe($path) && is_string($payload = json_encode($state))) {
        pmssAtomicWriteFile($path, $payload, 0600);
    }

    return [
        'delta' => ($previousIngress !== null && $currentIngress >= $previousIngress)
            ? $currentIngress - $previousIngress
            : $currentIngress,
        'previous_ingress' => $previousIngress,
    ];
}
