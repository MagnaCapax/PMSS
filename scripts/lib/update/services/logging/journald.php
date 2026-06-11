<?php
/**
 * Journald runtime cap policy and deployment helper.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Compute journald caps based on root filesystem size.
 *
 * @return array{system_max_use_bytes:int,system_keep_free_bytes:int,runtime_max_use_bytes:int,rate_limit_interval_sec:int,rate_limit_burst:int}
 */
function pmssJournaldLimitsForRootBytes(int $rootBytes): array
{
    $gib = 1024 * 1024 * 1024;
    $systemMax = max(2 * $gib, $rootBytes < 50 * $gib ? (int) floor($rootBytes * 0.20) : 20 * $gib);
    $systemKeepFree = max(1 * $gib, min(10 * $gib, (int) floor($rootBytes * 0.05)));
    $runtimeMax = max(256 * 1024 * 1024, min(2 * $gib, (int) floor($systemMax / 10)));
    return [
        'system_max_use_bytes'   => $systemMax,
        'system_keep_free_bytes' => $systemKeepFree,
        'runtime_max_use_bytes'  => $runtimeMax,
        'rate_limit_interval_sec'=> 10,
        'rate_limit_burst'       => 2000,
    ];
}

/** Convert a byte count to the compact units expected by journald templates. */
function pmssJournaldTemplateSize(int $bytes): string
{
    $gib = 1024 * 1024 * 1024;
    return $bytes > 0 && ($bytes % $gib) === 0 ? (string) ($bytes / $gib).'G' : (string) max(1, (int) floor($bytes / (1024 * 1024))).'M';
}

/** Render and install journald limits template, then restart journald. */
function pmssApplyJournaldLimits(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $rootBytes = (($override = getenv('PMSS_ROOT_FS_BYTES')) !== false && $override !== '' && ctype_digit($override))
        ? (int) $override
        : (((($bytes = @disk_total_space('/')) !== false) && is_numeric($bytes) && $bytes > 0) ? (int) $bytes : 0);
    if ($rootBytes <= 0) {
        $log('[WARN] Unable to determine root filesystem size; skipping journald limits');
        return;
    }
    $policy = pmssJournaldLimitsForRootBytes($rootBytes);
    $repl = [];
    foreach ([
        '%%PMSS_JOURNALD_SYSTEM_MAX_USE%%' => $policy['system_max_use_bytes'],
        '%%PMSS_JOURNALD_SYSTEM_KEEP_FREE%%' => $policy['system_keep_free_bytes'],
        '%%PMSS_JOURNALD_RUNTIME_MAX_USE%%' => $policy['runtime_max_use_bytes'],
    ] as $placeholder => $bytes) {
        $repl[$placeholder] = pmssJournaldTemplateSize((int) $bytes);
    }
    $repl += [
        '%%PMSS_JOURNALD_RATE_LIMIT_INTERVAL%%' => $policy['rate_limit_interval_sec'].'s',
        '%%PMSS_JOURNALD_RATE_LIMIT_BURST%%'    => (string) $policy['rate_limit_burst'],
    ];
    $rendered = pmssRenderLoggingTemplate(
        $cfgDir.'/template.journald.conf.d-pmss-limits.conf',
        $repl,
        '[SKIP] Journald limits template missing: ',
        '[WARN] Unable to read journald limits template: ',
        $log
    );
    if ($rendered === null) return;
    $targetDir = pmssResolvePathFromEnv('PMSS_JOURNALD_CONF_DIR', '/etc/systemd/journald.conf.d');
    if ((!is_dir($targetDir) || is_link($targetDir)) && !pmssEnsureSafeDir($targetDir, 0755)) {
        $log('[WARN] Unable to prepare journald config directory: '.$targetDir);
        return;
    }
    if (!pmssWriteManagedPathFile($targetDir.'/pmss-limits.conf', $rendered, 'journald limits', $log)) return;
    $log(sprintf('Applied journald limits: SystemMaxUse=%s RuntimeMaxUse=%s RateLimit=%ss/%d',
        $repl['%%PMSS_JOURNALD_SYSTEM_MAX_USE%%'],
        $repl['%%PMSS_JOURNALD_RUNTIME_MAX_USE%%'],
        $policy['rate_limit_interval_sec'],
        $policy['rate_limit_burst']
    ));
    if (($skipReason = pmssSystemdActionSkipReason(null, true, true)) !== '') {
        pmssLogStatus('SKIP', 'Restarting systemd-journald to apply log caps ('.$skipReason.')');
        return;
    }
    runStep('Restarting systemd-journald to apply log caps', 'systemctl restart systemd-journald');
}
