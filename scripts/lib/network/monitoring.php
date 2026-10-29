<?php
/** iptables owner-match discovery and checked monitoring-helper acquisition. @license GPL-3.0-only */

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

/**
 * Replace OUTPUT with the monitoring rules: one restore commit, else the previous
 * flush and rebuild one rule at a time. Returns 'none', 'atomic' or 'sequential'.
 */
function networkMonitoringOutputRulesApply(array $commands, ?callable $replaceAtomically = null, ?callable $runIptables = null, ?callable $logger = null): string
{
    if ($commands === []) {
        return 'none';
    }
    $replaceAtomically = $replaceAtomically ?? 'networkReplaceOutputRulesAtomically';
    if ($replaceAtomically($commands)) {
        return 'atomic';
    }

    // The reason (a refused rule or a failed restore) is already in iptables.log.
    ($logger ?? 'logMessage')('trafficLog: atomic OUTPUT replacement failed or was refused; rebuilding one rule at a time');
    $runIptables = $runIptables ?? 'networkRunIptables';
    $runIptables('-F OUTPUT');
    foreach ($commands as $command) {
        $runIptables($command);
    }
    return 'sequential';
}
