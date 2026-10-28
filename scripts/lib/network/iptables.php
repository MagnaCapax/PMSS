<?php
/**
 * iptables rule helpers for PMSS network setup.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/rules.php';
require_once __DIR__.'/monitoring.php';

/** Append a best-effort iptables log entry without surfacing logging failures. */
function networkIptablesLog(string $level, string $message): void
{
    @file_put_contents('/var/log/pmss/iptables.log', pmssLogFormatTimestampedLine("{$level} {$message}", 'c', ' '), FILE_APPEND);
}

function networkRunIptables(string $rule): void
{
    $rule = trim($rule);
    if (!networkIptablesCommandSafe($rule)) {
        networkIptablesLog('ERROR', "rejected unsafe iptables rule: {$rule}");
        return;
    }

    $cmd = '/sbin/iptables '.$rule;
    echo "Executing: {$cmd}\n";
    exec($cmd, $out, $ret);
    if ($ret !== 0) {
        networkIptablesLog('ERROR', $cmd);
    }
}

function networkApplyIptablesAtomically(array $filterCommands, array $natCommands): bool
{
    foreach (array_merge($filterCommands, $natCommands) as $command) {
        if (!is_string($command) || !networkIptablesCommandSafe($command)) {
            $summary = is_scalar($command) ? str_replace("\0", '<NUL>', (string) $command) : gettype($command);
            networkIptablesLog('ERROR', "rejected unsafe iptables-restore rule: {$summary}");
            return false;
        }
    }

    $data = '';
    foreach ([
        [$filterCommands, ['*filter', ':INPUT ACCEPT [0:0]', ':FORWARD ACCEPT [0:0]', ':OUTPUT ACCEPT [0:0]']],
        [$natCommands, ['*nat', ':PREROUTING ACCEPT [0:0]', ':INPUT ACCEPT [0:0]', ':OUTPUT ACCEPT [0:0]', ':POSTROUTING ACCEPT [0:0]']],
    ] as [$commands, $section]) {
        if (!$commands) {
            continue;
        }
        $data .= implode("\n", array_merge($section, array_values($commands), ['COMMIT']))."\n";
    }

    if ($data === '') {
        return true;
    }

    return networkIptablesRestore($data);
}

/**
 * Replace the filter OUTPUT chain with the monitoring rules in one commit.
 *
 * The in-transaction flush also resets the per-UID counters trafficLog bills
 * from (iptables-nft 1.8.7 on Debian 11 ignores -Z). --noflush keeps INPUT,
 * FORWARD and the other tables as they are.
 */
function networkReplaceOutputRulesAtomically(array $commands): bool
{
    foreach ($commands as $command) {
        // Only OUTPUT appends: anything else would reach another chain inside the *filter commit.
        if (!is_string($command) || strpos($command, '-A OUTPUT ') !== 0 || !networkIptablesCommandSafe($command)) {
            $summary = is_scalar($command) ? str_replace("\0", '<NUL>', (string) $command) : gettype($command);
            networkIptablesLog('ERROR', "rejected unsafe iptables-restore rule: {$summary}");
            return false;
        }
    }

    return networkIptablesRestore(implode("\n", array_merge(['*filter', '-F OUTPUT'], array_values($commands), ['COMMIT']))."\n", true);
}

/**
 * Feed a ruleset to iptables-restore through a private temp file. With $noflush
 * only the chains named in $data change. -w 30 waits at most 30 seconds for the
 * xtables lock (ADR 0035), so a held lock fails over to the caller's fallback.
 */
function networkIptablesRestore(string $data, bool $noflush = false): bool
{
    $tmp = pmssCreatePrivateTempFile('pmss-iptables-');
    if ($tmp === null) {
        networkIptablesLog('ERROR', 'unable to allocate iptables-restore temp file');
        return false;
    }
    try {
        // Never restore an incomplete ruleset, even when the write made progress.
        if (!pmssFileWriteComplete($tmp, $data)) {
            networkIptablesLog('ERROR', 'unable to write iptables-restore temp file');
            return false;
        }
        $command = sprintf('sh -c %s', escapeshellarg('iptables-restore'.($noflush ? ' -w 30 --noflush' : '').' < '.escapeshellarg($tmp)));
        return runCommand($command, false, 'logMessage') === 0;
    } finally {
        // Command execution or logging may throw; the private file is still ours.
        @unlink($tmp);
    }
}

function networkApplyIptablesFallback(array $filterCommands, array $natCommands, array $replacements): void
{
    $renderedSets = networkIptablesRenderCommandSets($filterCommands, $natCommands, $replacements);
    if ($renderedSets === null) {
        return;
    }

    foreach (['-F INPUT', '-F FORWARD', '-F OUTPUT', '-t nat -F POSTROUTING'] as $flushCommand) {
        networkRunIptables($flushCommand);
    }

    // The shared pair preserves filter-before-NAT order and explicit NAT prefixes.
    foreach ($renderedSets as $table => $commands) {
        foreach ($commands as $cmd) {
            networkRunIptables($table === 'nat' && strpos($cmd, '-t nat') !== 0 ? '-t nat '.$cmd : $cmd);
        }
    }
}
