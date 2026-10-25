<?php
/** iptables rule parsing, rendering and validation; application stays in iptables.php. @license GPL-3.0-only */

/**
 * Keep shell-executed iptables arguments to a single argv-like rule.
 */
function networkIptablesCommandSafe(string $rule): bool
{
    return $rule !== ''
        && $rule[0] === '-'
        && strpos($rule, "\0") === false
        && preg_match('/[;&|`$<>\\\\\r\n]/', $rule) !== 1;
}

function networkParseMonitoringCommands(string $raw): array
{
    $commands = [];
    foreach (pmssConfigTextLines($raw) as $line) {
        $line = trim(preg_replace('/^(\/sbin\/iptables|sbin\/iptables|iptables)\s+/', '', $line));
        if ($line === '' || strncmp($line, '-F', 2) === 0 || !networkIptablesCommandSafe($line)) {
            continue;
        }
        $commands[] = $line;
    }
    return $commands;
}

/** Render per-UID egress accounting commands for the monitoring chain. */
function networkMonitoringRulesRender(array $userUids, array $localnets): string
{
    $lastLocalNet = $localnets ? end($localnets) : '';
    $rules = "/sbin/iptables -A OUTPUT -d 127.0.0.0/8 -j ACCEPT\n";
    foreach ($userUids as $thisUid) {
        foreach ($localnets as $thisLocalNet) {
            $rules .= "/sbin/iptables -A OUTPUT -d {$thisLocalNet} -m owner --uid-owner {$thisUid} -j ACCEPT\n";
        }
        if ($lastLocalNet !== '') {
            $rules .= "/sbin/iptables -A OUTPUT ! -d {$lastLocalNet} -m owner --uid-owner {$thisUid} -j MARK --set-mark {$thisUid}\n";
        }
        $rules .= "/sbin/iptables -A OUTPUT -m owner --uid-owner {$thisUid} -j ACCEPT\n";
    }
    foreach ($localnets as $thisLocalNet) {
        $rules .= "/sbin/iptables -A OUTPUT -d {$thisLocalNet} -j ACCEPT\n";
    }
    return $rules;
}

/**
 * Render and validate iptables rules before any apply path sees them.
 *
 * @param array<int, mixed> $filterCommands
 * @param array<int, mixed> $natCommands
 * @param array<string, mixed> $replacements
 * @return ?array{filter:array<int,string>,nat:array<int,string>}
 */
function networkIptablesRenderCommandSets(array $filterCommands, array $natCommands, array $replacements): ?array
{
    foreach ($replacements as $search => $replace) {
        if (!is_string($search) || !is_string($replace)) {
            networkIptablesLog('ERROR', 'rejected non-string iptables replacement');
            return null;
        }
    }

    $renderedSets = ['filter' => [], 'nat' => []];
    $search = array_keys($replacements);
    $replace = array_values($replacements);
    foreach (['filter' => $filterCommands, 'nat' => $natCommands] as $section => $commands) {
        foreach ($commands as $cmd) {
            if (!is_string($cmd)) {
                networkIptablesLog('ERROR', 'rejected non-string iptables rule: '.gettype($cmd));
                return null;
            }
            $rendered = str_replace($search, $replace, $cmd);
            if (!networkIptablesCommandSafe($rendered)) {
                networkIptablesLog('ERROR', "rejected unsafe rendered iptables rule: {$rendered}");
                return null;
            }
            $renderedSets[$section][] = $rendered;
        }
    }

    return $renderedSets;
}
