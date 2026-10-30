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
    $result = pmssCounterStateUpdate($path, [
        'ingress' => (int) $counters['ingress'],
        'egress' => (int) $counters['egress'],
        'ts' => time(),
    ], ['ingress']);

    return [
        'delta' => $result['delta']['ingress'],
        'previous_ingress' => isset($result['previous_state']['ingress']) ? (int) $result['previous_state']['ingress'] : null,
    ];
}
