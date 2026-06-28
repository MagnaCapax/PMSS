<?php
/**
 * iptables rule helpers for PMSS network setup.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

/** Append a best-effort iptables log entry without surfacing logging failures. */
function networkIptablesLog(string $level, string $message): void
{
    @file_put_contents('/var/log/pmss/iptables.log', date('c')." {$level} {$message}\n", FILE_APPEND);
}

/**
 * Verify that the iptables owner match is available (xt_owner/ipt_owner).
 *
 * @return bool True when owner match can be used.
 */
function networkIptablesOwnerMatchAvailable(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $moduleStatus = 1;
    exec('lsmod | grep -q "^xt_owner\\b"', $null, $moduleStatus);
    if ($moduleStatus !== 0) {
        exec('modprobe xt_owner', $null, $rc);
        if ($rc !== 0) {
            exec('modprobe ipt_owner', $null, $rc);
        }
    }

    $output = [];
    $rc = 0;
    exec('/sbin/iptables -m owner -h 2>&1', $output, $rc);
    if ($rc !== 0) {
        $message = trim(implode("\n", $output));
        if ($message === '') {
            $message = 'no output';
        }
        networkIptablesLog('WARN', "owner match unavailable (rc={$rc}): {$message}");
        $cached = false;
        return $cached;
    }

    $cached = true;
    return $cached;
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
    if ($raw === '') {
        return [];
    }
    $commands = [];
    foreach (explode("\n", trim($raw)) as $line) {
        $line = ltrim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $line = trim(preg_replace('/^(\/sbin\/iptables|sbin\/iptables|iptables)\s+/', '', $line));
        if ($line === '' || strncmp($line, '-F', 2) === 0 || !networkIptablesCommandSafe($line)) {
            continue;
        }
        $commands[] = $line;
    }
    return $commands;
}

/**
 * Run the monitoring-rule helper and reject output from failed executions.
 */
function networkLoadMonitoringCommands(?callable $runner = null, ?callable $logger = null): array
{
    $runner = $runner ?? static function (array &$output, int &$rc): void {
        exec('/scripts/util/makeMonitoringRules.php', $output, $rc);
    };
    $logger = $logger ?? 'logMessage';
    $output = [];
    $rc = 1;

    try {
        $runner($output, $rc);
    } catch (Throwable $exception) {
        if (is_callable($logger)) {
            $logger('setupNetwork: failed to run monitoring rules helper: '.$exception->getMessage());
        }
        return [];
    }

    if ($rc !== 0) {
        if (is_callable($logger)) {
            $logger("setupNetwork: monitoring rules helper failed (rc={$rc}); skipping per-user monitoring rules");
        }
        return [];
    }

    return networkParseMonitoringCommands(implode("\n", array_map('strval', array_filter($output, 'is_scalar'))));
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

    $sections = [];
    foreach ([
        [$filterCommands, ['*filter', ':INPUT ACCEPT [0:0]', ':FORWARD ACCEPT [0:0]', ':OUTPUT ACCEPT [0:0]']],
        [$natCommands, ['*nat', ':PREROUTING ACCEPT [0:0]', ':INPUT ACCEPT [0:0]', ':OUTPUT ACCEPT [0:0]', ':POSTROUTING ACCEPT [0:0]']],
    ] as [$commands, $section]) {
        if (!$commands) {
            continue;
        }
        foreach ($commands as $cmd) {
            $section[] = $cmd;
        }
        $section[] = 'COMMIT';
        $sections[] = implode("\n", $section);
    }

    if (!$sections) {
        return true;
    }

    $data = implode("\n", $sections)."\n";
    $tmp = pmssCreatePrivateTempFile('pmss-iptables-');
    if ($tmp === null) {
        networkIptablesLog('ERROR', 'unable to allocate iptables-restore temp file');
        return false;
    }
    if (@file_put_contents($tmp, $data) === false) {
        @unlink($tmp);
        networkIptablesLog('ERROR', 'unable to write iptables-restore temp file');
        return false;
    }
    $command = sprintf('sh -c %s', escapeshellarg('iptables-restore < '.escapeshellarg($tmp)));
    $result = runCommand($command, false, 'logMessage');
    @unlink($tmp);
    return $result === 0;
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

    foreach ($renderedSets['filter'] as $cmd) {
        networkRunIptables($cmd);
    }
    foreach ($renderedSets['nat'] as $cmd) {
        if (strpos($cmd, '-t nat') !== 0) {
            $cmd = '-t nat '.$cmd;
        }
        networkRunIptables($cmd);
    }
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
