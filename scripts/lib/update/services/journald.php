<?php
/**
 * Journald limits management for PMSS hosts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime/commands.php';

if (!function_exists('pmssJournaldRootFilesystemBytes')) {
    /**
     * Return the root filesystem size in bytes, or 0 on failure.
     */
    function pmssJournaldRootFilesystemBytes(): int
    {
        $override = getenv('PMSS_ROOT_FS_BYTES');
        if (is_string($override) && $override !== '' && ctype_digit($override)) {
            return (int)$override;
        }
        $bytes = @disk_total_space('/');
        if ($bytes === false || !is_numeric($bytes) || $bytes <= 0) {
            return 0;
        }
        return (int)$bytes;
    }
}

if (!function_exists('pmssJournaldLimitsForRootBytes')) {
    /**
     * Compute journald caps based on root filesystem size.
     *
     * @return array{system_max_use_bytes:int,system_keep_free_bytes:int,runtime_max_use_bytes:int,rate_limit_interval_sec:int,rate_limit_burst:int}
     */
    function pmssJournaldLimitsForRootBytes(int $rootBytes): array
    {
        $gib = 1024 * 1024 * 1024;
        $mib = 1024 * 1024;
        $rootGiB = $rootBytes / $gib;

        // < 50GiB gets 20% with a 2GiB floor; larger roots use 20GiB flat.
        if ($rootGiB < 50) {
            $systemMax = (int)floor($rootBytes * 0.20);
        } else {
            $systemMax = 20 * $gib;
        }
        if ($systemMax < (2 * $gib)) {
            $systemMax = 2 * $gib;
        }

        // Keep free 5% of root, clamped to 1-10GiB.
        $systemKeepFree = (int)floor($rootBytes * 0.05);
        if ($systemKeepFree < (1 * $gib)) {
            $systemKeepFree = 1 * $gib;
        } elseif ($systemKeepFree > (10 * $gib)) {
            $systemKeepFree = 10 * $gib;
        }

        // Runtime max defaults to 10% of SystemMaxUse, clamped to 256MiB-2GiB.
        $runtimeMax = (int)floor($systemMax / 10);
        if ($runtimeMax < (256 * $mib)) {
            $runtimeMax = 256 * $mib;
        } elseif ($runtimeMax > (2 * $gib)) {
            $runtimeMax = 2 * $gib;
        }

        return [
            'system_max_use_bytes'   => $systemMax,
            'system_keep_free_bytes' => $systemKeepFree,
            'runtime_max_use_bytes'  => $runtimeMax,
            'rate_limit_interval_sec'=> 10,
            'rate_limit_burst'       => 2000,
        ];
    }
}

if (!function_exists('pmssJournaldFormatSize')) {
    /**
     * Format a byte count into journald-friendly units (GiB/MiB).
     */
    function pmssJournaldFormatSize(int $bytes): string
    {
        $gib = 1024 * 1024 * 1024;
        if ($bytes > 0 && ($bytes % $gib) === 0) {
            return (string)($bytes / $gib).'G';
        }
        $mib = 1024 * 1024;
        $mibVal = max(1, (int)floor($bytes / $mib));
        return (string)$mibVal.'M';
    }
}

if (!function_exists('pmssApplyJournaldLimits')) {
    /**
     * Render and install journald limits template, then restart journald.
     */
    function pmssApplyJournaldLimits(?callable $logger = null): void
    {
        $log = pmssSelectLogger($logger);
        $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
        $template = $cfgDir.'/template.journald.conf.d-pmss-limits.conf';
        if (!is_file($template)) {
            $log('[SKIP] Journald limits template missing: '.$template);
            return;
        }

        $rootBytes = pmssJournaldRootFilesystemBytes();
        if ($rootBytes <= 0) {
            $log('[WARN] Unable to determine root filesystem size; skipping journald limits');
            return;
        }

        $raw = @file_get_contents($template);
        if ($raw === false) {
            $log('[WARN] Unable to read journald limits template: '.$template);
            return;
        }

        $policy = pmssJournaldLimitsForRootBytes($rootBytes);
        $repl = [
            '%%PMSS_JOURNALD_SYSTEM_MAX_USE%%'    => pmssJournaldFormatSize($policy['system_max_use_bytes']),
            '%%PMSS_JOURNALD_SYSTEM_KEEP_FREE%%'  => pmssJournaldFormatSize($policy['system_keep_free_bytes']),
            '%%PMSS_JOURNALD_RUNTIME_MAX_USE%%'   => pmssJournaldFormatSize($policy['runtime_max_use_bytes']),
            '%%PMSS_JOURNALD_RATE_LIMIT_INTERVAL%%' => $policy['rate_limit_interval_sec'].'s',
            '%%PMSS_JOURNALD_RATE_LIMIT_BURST%%'    => (string)$policy['rate_limit_burst'],
        ];
        $raw = strtr($raw, $repl);

        $targetDir = pmssResolvePathFromEnv('PMSS_JOURNALD_CONF_DIR', '/etc/systemd/journald.conf.d');
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
            $log('[WARN] Unable to create journald config dir: '.$targetDir);
            return;
        }

        $target = $targetDir.'/pmss-limits.conf';
        $tmpTarget = $target.'.tmp';
        if (@file_put_contents($tmpTarget, $raw) === false) {
            $log('[WARN] Unable to write journald limits: '.$tmpTarget);
            return;
        }
        @chmod($tmpTarget, 0644);
        if (!@rename($tmpTarget, $target)) {
            $log('[WARN] Unable to install journald limits: '.$target);
            @unlink($tmpTarget);
            return;
        }

        $log(sprintf(
            'Applied journald limits: SystemMaxUse=%s RuntimeMaxUse=%s RateLimit=%ss/%d',
            $repl['%%PMSS_JOURNALD_SYSTEM_MAX_USE%%'],
            $repl['%%PMSS_JOURNALD_RUNTIME_MAX_USE%%'],
            $policy['rate_limit_interval_sec'],
            $policy['rate_limit_burst']
        ));

        $dryRun = getenv('PMSS_DRY_RUN') === '1';
        $testMode = defined('PMSS_TEST_MODE') && PMSS_TEST_MODE === true;
        if ($dryRun || $testMode) {
            pmssLogStatus('SKIP', 'Restarting systemd-journald to apply log caps (test/dry-run)');
            return;
        }
        if (!is_dir('/run/systemd/system')) {
            pmssLogStatus('SKIP', 'Restarting systemd-journald to apply log caps (systemd unavailable)');
            return;
        }
        runStep('Restarting systemd-journald to apply log caps', 'systemctl restart systemd-journald');
    }
}
