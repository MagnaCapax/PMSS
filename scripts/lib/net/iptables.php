<?php
/**
 * Shared iptables helpers for network provisioning scripts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

function iptablesRun(string $rule): void
{
    $cmd = "/sbin/iptables $rule";
    echo "Executing: $cmd\n";
    exec($cmd, $out, $ret);
    if ($ret !== 0) {
        file_put_contents('/var/log/pmss/iptables.log', date('c') . " ERROR $cmd\n", FILE_APPEND);
    }
}

function iptablesParseMonitoring(string $raw): array
{
    if ($raw === '') return [];
    $commands = [];
    foreach (explode("\n", trim($raw)) as $line) {
        $line = trim(preg_replace('/^\/?sbin\/iptables\s+/', '', $line));
        if ($line === '' || strpos($line, '-F') === 0) continue;
        $commands[] = $line;
    }
    return $commands;
}

function iptablesApplyAtomically(array $filterCommands, array $natCommands): bool
{
    // #TODO Add hermetic tests that assert the rendered iptables-restore
    //       payload structure for both filter and nat sections.
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

    $data = implode("\n", $sections) . "\n";
    $tmp = tempnam(sys_get_temp_dir(), 'pmss-iptables-');
    file_put_contents($tmp, $data);
    $command = sprintf('sh -c %s', escapeshellarg('iptables-restore < ' . escapeshellarg($tmp)));
    $result = runCommand($command, false, 'logMessage');
    unlink($tmp);
    return $result === 0;
}

function iptablesApplyFallback(array $filterCommands, array $natCommands, array $replacements): void
{
    // #TODO Add tests to validate replacement and nat prefixing behavior.
    iptablesRun('-F INPUT');
    iptablesRun('-F FORWARD');
    iptablesRun('-F OUTPUT');
    iptablesRun('-t nat -F POSTROUTING');
    foreach ($filterCommands as $cmd) {
        iptablesRun(str_replace(array_keys($replacements), array_values($replacements), $cmd));
    }
    foreach ($natCommands as $cmd) {
        $rendered = str_replace(array_keys($replacements), array_values($replacements), $cmd);
        if (strpos($rendered, '-t nat') !== 0) {
            iptablesRun('-t nat ' . $rendered);
        } else {
            iptablesRun($rendered);
        }
    }
}
