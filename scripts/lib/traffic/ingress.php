<?php
/**
 * Helpers for per-user ingress traffic accounting via systemd IPAccounting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../resources/log.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/../systemdSliceProperties.php';

/**
 * Read systemd IPAccounting counters for the user's slice.
 */
function pmssTrafficIngressReadCounters(int $uid): ?array
{
    return pmssReadSystemdIntProperties(sprintf('user-%d.slice', $uid), ['IPIngressBytes' => 'ingress', 'IPEgressBytes' => 'egress']);
}

/**
 * Load the last-seen counters from a state file.
 */
function pmssTrafficIngressReadState(string $path): array
{
    return pmssJsonFileReadAssoc($path, true) ?? [];
}

/**
 * Persist the latest counters to a state file.
 */
function pmssTrafficIngressWriteState(string $path, array $state): void
{
    if ($path === '' || !pmssUserFilePathIsSafe($path)) {
        return;
    }

    $payload = json_encode($state);
    if (!is_string($payload)) {
        return;
    }

    pmssAtomicWriteFile($path, $payload, 0600);
}
