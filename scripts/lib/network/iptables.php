<?php
/**
 * iptables rule helpers for PMSS network setup.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

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
        file_put_contents(
            '/var/log/pmss/iptables.log',
            date('c')." WARN owner match unavailable (rc={$rc}): {$message}\n",
            FILE_APPEND
        );
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
        file_put_contents(
            '/var/log/pmss/iptables.log',
            date('c')." ERROR rejected unsafe iptables rule: {$rule}\n",
            FILE_APPEND
        );
        return;
    }

    $cmd = '/sbin/iptables '.$rule;
    echo "Executing: {$cmd}\n";
    exec($cmd, $out, $ret);
    if ($ret !== 0) {
        file_put_contents('/var/log/pmss/iptables.log', date('c')." ERROR {$cmd}\n", FILE_APPEND);
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
        $line = trim(preg_replace('/^\/?sbin\/iptables\s+/', '', $line));
        if (strpos($line, 'iptables ') === 0) {
            $line = trim(substr($line, strlen('iptables ')));
        }
        if ($line === '' || strncmp($line, '-F', 2) === 0) {
            continue;
        }
        if (!networkIptablesCommandSafe($line)) {
            continue;
        }
        $commands[] = $line;
    }
    return $commands;
}

function networkApplyIptablesAtomically(array $filterCommands, array $natCommands): bool
{
    foreach (array_merge($filterCommands, $natCommands) as $command) {
        if (!is_string($command) || !networkIptablesCommandSafe($command)) {
            $summary = is_scalar($command) ? str_replace("\0", '<NUL>', (string) $command) : gettype($command);
            @file_put_contents(
                '/var/log/pmss/iptables.log',
                date('c')." ERROR rejected unsafe iptables-restore rule: {$summary}\n",
                FILE_APPEND
            );
            return false;
        }
    }

    $sections = [];
    if ($filterCommands) {
        $filter = ['*filter', ':INPUT ACCEPT [0:0]', ':FORWARD ACCEPT [0:0]', ':OUTPUT ACCEPT [0:0]'];
        foreach ($filterCommands as $cmd) {
            $filter[] = $cmd;
        }
        $filter[] = 'COMMIT';
        $sections[] = implode("\n", $filter);
    }
    if ($natCommands) {
        $nat = ['*nat', ':PREROUTING ACCEPT [0:0]', ':INPUT ACCEPT [0:0]', ':OUTPUT ACCEPT [0:0]', ':POSTROUTING ACCEPT [0:0]'];
        foreach ($natCommands as $cmd) {
            $nat[] = $cmd;
        }
        $nat[] = 'COMMIT';
        $sections[] = implode("\n", $nat);
    }

    if (!$sections) {
        return true;
    }

    $data = implode("\n", $sections)."\n";
    $tmp = tempnam(sys_get_temp_dir(), 'pmss-iptables-');
    if (!is_string($tmp) || $tmp === '') {
        @file_put_contents('/var/log/pmss/iptables.log', date('c')." ERROR unable to allocate iptables-restore temp file\n", FILE_APPEND);
        return false;
    }
    if (@file_put_contents($tmp, $data) === false) {
        @unlink($tmp);
        @file_put_contents('/var/log/pmss/iptables.log', date('c')." ERROR unable to write iptables-restore temp file\n", FILE_APPEND);
        return false;
    }
    $command = sprintf('sh -c %s', escapeshellarg('iptables-restore < '.escapeshellarg($tmp)));
    $result = runCommand($command, false, 'logMessage');
    @unlink($tmp);
    return $result === 0;
}

function networkApplyIptablesFallback(array $filterCommands, array $natCommands, array $replacements): void
{
    networkRunIptables('-F INPUT');
    networkRunIptables('-F FORWARD');
    networkRunIptables('-F OUTPUT');
    networkRunIptables('-t nat -F POSTROUTING');

    foreach ($filterCommands as $cmd) {
        networkRunIptables(str_replace(array_keys($replacements), array_values($replacements), $cmd));
    }
    foreach ($natCommands as $cmd) {
        $rendered = str_replace(array_keys($replacements), array_values($replacements), $cmd);
        if (strpos($rendered, '-t nat') !== 0) {
            networkRunIptables('-t nat '.$rendered);
        } else {
            networkRunIptables($rendered);
        }
    }
}
