<?php
/**
 * Backwards-compatible wrappers for the canonical network iptables helpers.
 *
 * Keep legacy includes working while the implementation lives in one place
 * under `scripts/lib/network/iptables.php`.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../network/iptables.php';

function iptablesRun(string $rule): void
{
    networkRunIptables($rule);
}

function iptablesParseMonitoring(string $raw): array
{
    return networkParseMonitoringCommands($raw);
}

function iptablesApplyAtomically(array $filterCommands, array $natCommands): bool
{
    return networkApplyIptablesAtomically($filterCommands, $natCommands);
}

function iptablesApplyFallback(array $filterCommands, array $natCommands, array $replacements): void
{
    networkApplyIptablesFallback($filterCommands, $natCommands, $replacements);
}
