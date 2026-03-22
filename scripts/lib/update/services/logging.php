<?php
/**
 * Remote logging configuration for PMSS hosts.
 *
 * Reads configuration from /etc/seedbox/config/logging.conf if it exists
 * and deploys rsyslog remote forwarding config when enabled.
 *
 * This module is BEST-EFFORT ONLY: failures never abort the update process.
 * Remote logging is DISABLED by default and must be explicitly enabled
 * via logging.conf.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../../runtime.php';

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
 * Render and install journald limits template, then restart journald.
 */
function pmssApplyJournaldLimits(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $template = $cfgDir.'/template.journald.conf.d-pmss-limits.conf';
    if (!is_file($template)) {
        $log('[SKIP] Journald limits template missing: '.$template);
        return;
    }

    $rootBytes = (($override = getenv('PMSS_ROOT_FS_BYTES')) !== false && $override !== '' && ctype_digit($override))
        ? (int) $override
        : (((($bytes = @disk_total_space('/')) !== false) && is_numeric($bytes) && $bytes > 0) ? (int) $bytes : 0);
    if ($rootBytes <= 0) {
        $log('[WARN] Unable to determine root filesystem size; skipping journald limits');
        return;
    }

    if (($raw = @file_get_contents($template)) === false) {
        $log('[WARN] Unable to read journald limits template: '.$template);
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
    $raw = strtr($raw, $repl);

    $targetDir = pmssResolvePathFromEnv('PMSS_JOURNALD_CONF_DIR', '/etc/systemd/journald.conf.d');
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
        $log('[WARN] Unable to create journald config dir: '.$targetDir);
        return;
    }

    $target = $targetDir.'/pmss-limits.conf';
    if (@file_put_contents($tmpTarget = $target.'.tmp', $raw) === false) {
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

    $skipReason = getenv('PMSS_DRY_RUN') === '1' || (defined('PMSS_TEST_MODE') && PMSS_TEST_MODE === true)
        ? 'test/dry-run'
        : (!is_dir('/run/systemd/system') ? 'systemd unavailable' : '');
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
    $skipRestart = getenv('PMSS_DRY_RUN') === '1' || (defined('PMSS_TEST_MODE') && PMSS_TEST_MODE === true);

    // Check for logging.conf - if not present, silently skip (disabled by default)
    if (!is_file($loggingConf)) {
        // Silent skip - remote logging disabled by default
        return;
    }

    $config = [
        'enabled'  => false,
        'host'     => '',
        'port'     => 514,
        'protocol' => 'tcp',
    ];
    if (is_readable($loggingConf) && ($rawConfig = @file_get_contents($loggingConf)) !== false && trim($rawConfig) !== '') {
        foreach (preg_split('/\r?\n/', $rawConfig) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';' || count($parts = explode('=', $line, 2)) !== 2) {
                continue;
            }

            $key = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            switch ($key) {
                case 'remote_logging_enabled':
                    $config['enabled'] = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
                    break;
                case 'remote_host':
                    $config['host'] = $value;
                    break;
                case 'remote_port':
                    if (ctype_digit($value) && ($port = (int) $value) > 0 && $port <= 65535) {
                        $config['port'] = $port;
                    }
                    break;
                case 'remote_protocol':
                    if (in_array($lower = strtolower($value), ['tcp', 'udp'], true)) {
                        $config['protocol'] = $lower;
                    }
                    break;
            }
        }
    }

    $invalidReason = !$config['enabled']
        ? 'Remote logging not enabled'
        : ($config['host'] === ''
            ? 'Remote host not configured'
            : (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-:]+$/', $config['host']) ? '' : 'Invalid remote host format'));

    if ($invalidReason !== '') {
        if ($config['enabled']) {
            // Only warn if explicitly enabled but misconfigured
            $log('[WARN] Remote logging enabled but invalid: '.$invalidReason);
        }
        // Ensure no stale config exists when disabled
        if (!is_file($target)) {
            return;
        }
        if (!@unlink($target)) {
            $log('[WARN] Unable to remove remote logging config: '.$target);
            return;
        }

        $log('Removed remote logging config (disabled)');
        if (!$skipRestart) {
            runStep('Restarting rsyslog after removing remote forwarding', 'systemctl restart rsyslog');
        }
        return;
    }

    // Check template exists
    if (!is_file($template)) {
        $log('[WARN] Remote logging template missing: '.$template);
        return;
    }

    if (($raw = @file_get_contents($template)) === false) {
        $log('[WARN] Unable to read remote logging template: '.$template);
        return;
    }

    // Apply substitutions
    $rendered = strtr($raw, [
        '%%PMSS_RSYSLOG_REMOTE_HOST%%' => $config['host'],
        '%%PMSS_RSYSLOG_REMOTE_PORT%%' => (string) $config['port'],
        '%%PMSS_RSYSLOG_PROTOCOL%%'    => $config['protocol'],
    ]);

    // Deploy to rsyslog.d
    if (!is_dir($targetDir)) {
        // rsyslog not installed or unusual setup; skip silently
        $log('[SKIP] rsyslog conf.d directory not found: '.$targetDir);
        return;
    }

    if (@file_put_contents($tmpTarget = $target.'.tmp', $rendered) === false) {
        $log('[WARN] Unable to write remote logging config: '.$tmpTarget);
        return;
    }
    @chmod($tmpTarget, 0644);

    if (!@rename($tmpTarget, $target)) {
        $log('[WARN] Unable to install remote logging config: '.$target);
        @unlink($tmpTarget);
        return;
    }

    $log(sprintf(
        'Applied remote logging: %s:%d (%s)',
        $config['host'],
        $config['port'],
        $config['protocol']
    ));

    // Restart rsyslog to apply changes (best-effort)
    if ($skipRestart) {
        pmssLogStatus('SKIP', 'Restarting rsyslog to apply remote forwarding (test/dry-run)');
        return;
    }

    // Check if rsyslog service exists before attempting restart
    if (!@file_exists('/lib/systemd/system/rsyslog.service') && !@file_exists('/etc/init.d/rsyslog')) {
        $log('[SKIP] rsyslog service not found; config deployed but service not restarted');
        return;
    }

    runStep('Restarting rsyslog to apply remote forwarding', 'systemctl restart rsyslog');
}
