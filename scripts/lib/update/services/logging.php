<?php
/**
 * Best-effort journald and remote logging configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../../runtime.php';
pmssRequireRelativeFiles(__DIR__, ['../managedPath.php', '../runtime/commands.php', '../runtime/processes.php']);

/** Read a logging template and replace placeholders in one pass. */
function pmssRenderLoggingTemplate(
    string $template,
    array $replacements,
    string $missingMessage,
    string $readErrorMessage,
    callable $logger
): ?string {
    if (!is_file($template)) {
        $logger($missingMessage.$template);
        return null;
    }
    $raw = @file_get_contents($template);
    if ($raw === false) {
        $logger($readErrorMessage.$template);
        return null;
    }
    return strtr($raw, $replacements);
}

/**
 * Compute journald caps based on root filesystem size.
 *
 * @return array{system_max_use_bytes:int,system_keep_free_bytes:int,runtime_max_use_bytes:int,rate_limit_interval_sec:int,rate_limit_burst:int}
 */
function pmssJournaldLimitsForRootBytes(int $rootBytes): array
{
    $gib = 1024 * 1024 * 1024;
    // < 50GiB gets 20% with a 2GiB floor; larger roots use 20GiB flat.
    $systemMax = max(2 * $gib, $rootBytes < 50 * $gib ? (int) floor($rootBytes * 0.20) : 20 * $gib);
    // Keep free 5% of root, clamped to 1-10GiB.
    $systemKeepFree = max(1 * $gib, min(10 * $gib, (int) floor($rootBytes * 0.05)));
    // Runtime max defaults to 10% of SystemMaxUse, clamped to 256MiB-2GiB.
    $runtimeMax = max(256 * 1024 * 1024, min(2 * $gib, (int) floor($systemMax / 10)));
    return [
        'system_max_use_bytes'   => $systemMax,
        'system_keep_free_bytes' => $systemKeepFree,
        'runtime_max_use_bytes'  => $runtimeMax,
        'rate_limit_interval_sec'=> 10,
        'rate_limit_burst'       => 2000,
    ];
}

/**
 * Read remote logging config while ignoring malformed lines and unknown keys.
 *
 * @return array{enabled:bool,host:string,port:int,protocol:string}
 */
function pmssRemoteLoggingReadConfig(string $loggingConf): array
{
    $config = ['enabled' => false, 'host' => '', 'port' => 514, 'protocol' => 'tcp'];
    if (!is_readable($loggingConf) || ($rawConfig = @file_get_contents($loggingConf)) === false || trim($rawConfig) === '') {
        return $config;
    }

    $parsed = @parse_ini_string($rawConfig, false, INI_SCANNER_RAW);
    if (!is_array($parsed)) {
        return $config;
    }
    $config['enabled'] = isset($parsed['remote_logging_enabled'])
        ? pmssEnvValueIsTruthy($parsed['remote_logging_enabled'])
        : $config['enabled'];
    $config['host'] = isset($parsed['remote_host'])
        ? trim((string) $parsed['remote_host'])
        : $config['host'];
    $port = trim((string) ($parsed['remote_port'] ?? ''));
    if (($parsedPort = pmssNetworkPortParseDigits($port)) !== null) {
        $config['port'] = $parsedPort;
    }
    $protocol = strtolower(trim((string) ($parsed['remote_protocol'] ?? '')));
    if (in_array($protocol, ['tcp', 'udp'], true)) {
        $config['protocol'] = $protocol;
    }
    return $config;
}

/** Explain why remote logging should not be applied. */
function pmssRemoteLoggingInvalidReason(array $config): string
{
    if (!$config['enabled']) { return 'Remote logging not enabled'; }
    if ($config['host'] === '') { return 'Remote host not configured'; }
    return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-:]+$/', $config['host']) === 1
        ? ''
        : 'Invalid remote host format';
}

/**
 * Render and install journald limits template, then restart journald.
 */
function pmssApplyJournaldLimits(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $template = $cfgDir.'/template.journald.conf.d-pmss-limits.conf';
    $rootBytes = (($override = getenv('PMSS_ROOT_FS_BYTES')) !== false && $override !== '' && ctype_digit($override))
        ? (int) $override
        : (((($bytes = @disk_total_space('/')) !== false) && is_numeric($bytes) && $bytes > 0) ? (int) $bytes : 0);
    if ($rootBytes <= 0) {
        $log('[WARN] Unable to determine root filesystem size; skipping journald limits');
        return;
    }
    $policy = pmssJournaldLimitsForRootBytes($rootBytes);
    $gib = 1024 * 1024 * 1024;
    $repl = [];
    foreach ([
        '%%PMSS_JOURNALD_SYSTEM_MAX_USE%%' => $policy['system_max_use_bytes'],
        '%%PMSS_JOURNALD_SYSTEM_KEEP_FREE%%' => $policy['system_keep_free_bytes'],
        '%%PMSS_JOURNALD_RUNTIME_MAX_USE%%' => $policy['runtime_max_use_bytes'],
    ] as $placeholder => $bytes) {
        $repl[$placeholder] = ($bytes > 0 && ($bytes % $gib) === 0)
            ? (string) ($bytes / $gib).'G'
            : (string) max(1, (int) floor($bytes / (1024 * 1024))).'M';
    }
    $repl += [
        '%%PMSS_JOURNALD_RATE_LIMIT_INTERVAL%%' => $policy['rate_limit_interval_sec'].'s',
        '%%PMSS_JOURNALD_RATE_LIMIT_BURST%%'    => (string) $policy['rate_limit_burst'],
    ];
    $rendered = pmssRenderLoggingTemplate(
        $template,
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
    $target = $targetDir.'/pmss-limits.conf';
    if (!pmssWriteManagedPathFile($target, $rendered, 'journald limits', $log)) return;
    $log(sprintf(
        'Applied journald limits: SystemMaxUse=%s RuntimeMaxUse=%s RateLimit=%ss/%d',
        $repl['%%PMSS_JOURNALD_SYSTEM_MAX_USE%%'],
        $repl['%%PMSS_JOURNALD_RUNTIME_MAX_USE%%'],
        $policy['rate_limit_interval_sec'],
        $policy['rate_limit_burst']
    ));
    $skipReason = pmssSystemdActionSkipReason(null, true, true);
    if ($skipReason !== '') { pmssLogStatus('SKIP', 'Restarting systemd-journald to apply log caps ('.$skipReason.')'); return; }
    runStep('Restarting systemd-journald to apply log caps', 'systemctl restart systemd-journald');
}

/**
 * Deploy rsyslog remote forwarding configuration if enabled.
 *
 * This function is best-effort: any failure logs a warning but never
 * aborts the update process. Remote logging is optional infrastructure.
 */
function pmssApplyRemoteLogging(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $loggingConf = $cfgDir.'/logging.conf';
    $template = $cfgDir.'/template.rsyslog-remote.conf';
    $targetDir = pmssResolvePathFromEnv('PMSS_RSYSLOG_CONF_DIR', '/etc/rsyslog.d');
    $target = $targetDir.'/50-pmss-remote.conf';
    $skipRestartReason = pmssSystemdActionSkipReason(null, true, true);
    $skipRestart = $skipRestartReason !== '';
    if (!is_file($loggingConf)) return;
    $config = pmssRemoteLoggingReadConfig($loggingConf);
    $invalidReason = pmssRemoteLoggingInvalidReason($config);
    if ($invalidReason !== '') {
        if ($config['enabled']) {
            // Only warn if explicitly enabled but misconfigured
            $log('[WARN] Remote logging enabled but invalid: '.$invalidReason);
        }
        // Ensure no stale config exists when disabled
        if (!is_file($target)) {
            return;
        }
        if (!pmssRemoveManagedPathFile($target, 'remote logging config', $log)) {
            $log('[WARN] Unable to remove remote logging config: '.$target);
            return;
        }

        $log('Removed remote logging config (disabled)');
        if (!$skipRestart) {
            runStep('Restarting rsyslog after removing remote forwarding', 'systemctl restart rsyslog');
        }
        return;
    }
    $rendered = pmssRenderLoggingTemplate(
        $template,
        [
            '%%PMSS_RSYSLOG_REMOTE_HOST%%' => $config['host'],
            '%%PMSS_RSYSLOG_REMOTE_PORT%%' => (string) $config['port'],
            '%%PMSS_RSYSLOG_PROTOCOL%%'    => $config['protocol'],
        ],
        '[WARN] Remote logging template missing: ',
        '[WARN] Unable to read remote logging template: ',
        $log
    );
    if ($rendered === null) return;
    if (!is_dir($targetDir)) {
        $log('[SKIP] rsyslog conf.d directory not found: '.$targetDir);
        return;
    }
    if (!pmssWriteManagedPathFile($target, $rendered, 'remote logging config', $log)) return;
    $log(sprintf(
        'Applied remote logging: %s:%d (%s)',
        $config['host'],
        $config['port'],
        $config['protocol']
    ));
    if ($skipRestart) {
        pmssLogStatus('SKIP', 'Restarting rsyslog to apply remote forwarding ('.$skipRestartReason.')');
        return;
    }
    if (!@file_exists('/lib/systemd/system/rsyslog.service') && !@file_exists('/etc/init.d/rsyslog')) {
        $log('[SKIP] rsyslog service not found; config deployed but service not restarted');
        return;
    }

    runStep('Restarting rsyslog to apply remote forwarding', 'systemctl restart rsyslog');
}
