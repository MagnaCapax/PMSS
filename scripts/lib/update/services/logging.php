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

if (!function_exists('pmssParseLoggingConfig')) {
    /**
     * Parse the logging.conf file and return configuration array.
     *
     * Expected format (INI-style):
     *   remote_logging_enabled=1
     *   remote_host=logserver.example.com
     *   remote_port=514
     *   remote_protocol=tcp
     *
     * @return array{enabled:bool,host:string,port:int,protocol:string}
     */
    function pmssParseLoggingConfig(string $configPath): array
    {
        $defaults = [
            'enabled'  => false,
            'host'     => '',
            'port'     => 514,
            'protocol' => 'tcp',
        ];

        if (!is_file($configPath) || !is_readable($configPath)) {
            return $defaults;
        }

        $raw = @file_get_contents($configPath);
        if ($raw === false || trim($raw) === '') {
            return $defaults;
        }

        $config = $defaults;
        $lines = preg_split('/\r?\n/', $raw);
        if (!is_array($lines)) {
            return $defaults;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip empty lines and comments
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
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
                    if (ctype_digit($value) && (int)$value > 0 && (int)$value <= 65535) {
                        $config['port'] = (int)$value;
                    }
                    break;
                case 'remote_protocol':
                    $lower = strtolower($value);
                    if ($lower === 'tcp' || $lower === 'udp') {
                        $config['protocol'] = $lower;
                    }
                    break;
            }
        }

        return $config;
    }
}

if (!function_exists('pmssValidateLoggingConfig')) {
    /**
     * Validate that a logging configuration is deployable.
     *
     * @return array{valid:bool,error:string}
     */
    function pmssValidateLoggingConfig(array $config): array
    {
        if (!$config['enabled']) {
            return ['valid' => false, 'error' => 'Remote logging not enabled'];
        }

        if ($config['host'] === '') {
            return ['valid' => false, 'error' => 'Remote host not configured'];
        }

        // Basic hostname/IP validation - allow hostnames, IPv4, IPv6
        $host = $config['host'];
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-:]+$/', $host)) {
            return ['valid' => false, 'error' => 'Invalid remote host format'];
        }

        return ['valid' => true, 'error' => ''];
    }
}

if (!function_exists('pmssApplyRemoteLogging')) {
    /**
     * Deploy rsyslog remote forwarding configuration if enabled.
     *
     * This function is best-effort: any failure logs a warning but never
     * aborts the update process. Remote logging is optional infrastructure.
     */
    function pmssApplyRemoteLogging(?callable $logger = null): void
    {
        $log = pmssSelectLogger($logger);
        $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
        $loggingConf = $cfgDir.'/logging.conf';
        $template = $cfgDir.'/template.rsyslog-remote.conf';

        // Check for logging.conf - if not present, silently skip (disabled by default)
        if (!is_file($loggingConf)) {
            // Silent skip - remote logging disabled by default
            return;
        }

        $config = pmssParseLoggingConfig($loggingConf);
        $validation = pmssValidateLoggingConfig($config);

        if (!$validation['valid']) {
            if ($config['enabled']) {
                // Only warn if explicitly enabled but misconfigured
                $log('[WARN] Remote logging enabled but invalid: '.$validation['error']);
            }
            // Ensure no stale config exists when disabled
            pmssRemoveRemoteLoggingConfig($log);
            return;
        }

        // Check template exists
        if (!is_file($template)) {
            $log('[WARN] Remote logging template missing: '.$template);
            return;
        }

        $raw = @file_get_contents($template);
        if ($raw === false) {
            $log('[WARN] Unable to read remote logging template: '.$template);
            return;
        }

        // Apply substitutions
        $repl = [
            '%%PMSS_RSYSLOG_REMOTE_HOST%%' => $config['host'],
            '%%PMSS_RSYSLOG_REMOTE_PORT%%' => (string)$config['port'],
            '%%PMSS_RSYSLOG_PROTOCOL%%'    => $config['protocol'],
        ];
        $rendered = strtr($raw, $repl);

        // Deploy to rsyslog.d
        $targetDir = pmssResolvePathFromEnv('PMSS_RSYSLOG_CONF_DIR', '/etc/rsyslog.d');
        if (!is_dir($targetDir)) {
            // rsyslog not installed or unusual setup; skip silently
            $log('[SKIP] rsyslog conf.d directory not found: '.$targetDir);
            return;
        }

        $target = $targetDir.'/50-pmss-remote.conf';
        $tmpTarget = $target.'.tmp';

        if (@file_put_contents($tmpTarget, $rendered) === false) {
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
        $dryRun = getenv('PMSS_DRY_RUN') === '1';
        $testMode = defined('PMSS_TEST_MODE') && PMSS_TEST_MODE === true;
        if ($dryRun || $testMode) {
            pmssLogStatus('SKIP', 'Restarting rsyslog to apply remote forwarding (test/dry-run)');
            return;
        }

        // Check if rsyslog service exists before attempting restart
        $rsyslogActive = @file_exists('/lib/systemd/system/rsyslog.service')
                      || @file_exists('/etc/init.d/rsyslog');
        if (!$rsyslogActive) {
            $log('[SKIP] rsyslog service not found; config deployed but service not restarted');
            return;
        }

        runStep('Restarting rsyslog to apply remote forwarding', 'systemctl restart rsyslog');
    }
}

if (!function_exists('pmssRemoveRemoteLoggingConfig')) {
    /**
     * Remove the remote logging rsyslog config when disabled.
     *
     * Ensures no stale forwarding config remains when logging is turned off.
     */
    function pmssRemoveRemoteLoggingConfig(?callable $logger = null): void
    {
        $log = pmssSelectLogger($logger);
        $targetDir = pmssResolvePathFromEnv('PMSS_RSYSLOG_CONF_DIR', '/etc/rsyslog.d');
        $target = $targetDir.'/50-pmss-remote.conf';

        if (!is_file($target)) {
            return;
        }

        if (@unlink($target)) {
            $log('Removed remote logging config (disabled)');

            // Restart rsyslog to stop forwarding
            $dryRun = getenv('PMSS_DRY_RUN') === '1';
            $testMode = defined('PMSS_TEST_MODE') && PMSS_TEST_MODE === true;
            if (!$dryRun && !$testMode) {
                runStep('Restarting rsyslog after removing remote forwarding', 'systemctl restart rsyslog');
            }
        } else {
            $log('[WARN] Unable to remove remote logging config: '.$target);
        }
    }
}
