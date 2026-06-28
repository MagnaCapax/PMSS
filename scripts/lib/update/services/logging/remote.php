<?php
/**
 * Optional rsyslog remote forwarding configuration helper.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Read remote logging config while ignoring malformed lines and unknown keys.
 *
 * @return array{enabled:bool,host:string,port:int,protocol:string}
 */
function pmssRemoteLoggingReadConfig(string $loggingConf): array
{
    $config = ['enabled' => false, 'host' => '', 'port' => 514, 'protocol' => 'tcp'];
    if (($rawConfig = pmssReadRegularFileContents($loggingConf)) === null || trim($rawConfig) === '') {
        return $config;
    }
    if (!is_array($parsed = @parse_ini_string($rawConfig, false, INI_SCANNER_RAW))) {
        return $config;
    }
    $config['enabled'] = isset($parsed['remote_logging_enabled'])
        ? pmssEnvValueIsTruthy($parsed['remote_logging_enabled'])
        : $config['enabled'];
    $config['host'] = isset($parsed['remote_host']) ? trim((string) $parsed['remote_host']) : $config['host'];
    if (($parsedPort = pmssNetworkPortParseDigits(trim((string) ($parsed['remote_port'] ?? '')))) !== null) $config['port'] = $parsedPort;
    $protocol = strtolower(trim((string) ($parsed['remote_protocol'] ?? '')));
    if (in_array($protocol, ['tcp', 'udp'], true)) $config['protocol'] = $protocol;
    return $config;
}

/** Classify remote-forwarding state without touching the filesystem. */
function pmssRemoteLoggingInvalidReason(array $config): string
{
    if (empty($config['enabled'])) return 'Remote logging not enabled';
    if ((string) ($config['host'] ?? '') === '') return 'Remote host not configured';
    return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-:]+$/', (string) $config['host']) === 1
        ? ''
        : 'Invalid remote host format';
}

/** Deploy optional rsyslog remote forwarding; failures are warning-only. */
function pmssApplyRemoteLogging(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    if (!is_file($loggingConf = $cfgDir.'/logging.conf')) return;
    $targetDir = pmssResolvePathFromEnv('PMSS_RSYSLOG_CONF_DIR', '/etc/rsyslog.d');
    $target = $targetDir.'/50-pmss-remote.conf';
    $skipRestartReason = pmssSystemdActionSkipReason(null, true, true);
    $config = pmssRemoteLoggingReadConfig($loggingConf);
    $invalidReason = pmssRemoteLoggingInvalidReason($config);
    if ($invalidReason !== '') {
        if ($config['enabled']) {
            $log('[WARN] Remote logging enabled but invalid: '.$invalidReason);
        }
        if (!is_file($target)) return;
        if (!pmssRemoveManagedPathFile($target, 'remote logging config', $log)) {
            $log('[WARN] Unable to remove remote logging config: '.$target);
            return;
        }
        $log('Removed remote logging config (disabled)');
        if ($skipRestartReason === '') {
            runStep('Restarting rsyslog after removing remote forwarding', 'systemctl restart rsyslog');
        }
        return;
    }
    $rendered = pmssRenderLoggingTemplate(
        $cfgDir.'/template.rsyslog-remote.conf',
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
    $log(sprintf('Applied remote logging: %s:%d (%s)', $config['host'], $config['port'], $config['protocol']));
    if ($skipRestartReason !== '') {
        pmssLogStatus('SKIP', 'Restarting rsyslog to apply remote forwarding ('.$skipRestartReason.')');
        return;
    }
    if (!@file_exists('/lib/systemd/system/rsyslog.service') && !@file_exists('/etc/init.d/rsyslog')) {
        $log('[SKIP] rsyslog service not found; config deployed but service not restarted');
        return;
    }
    runStep('Restarting rsyslog to apply remote forwarding', 'systemctl restart rsyslog');
}
