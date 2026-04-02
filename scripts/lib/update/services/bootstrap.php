<?php
/**
 * Baseline service helpers for installer bootstrap and runtime refresh.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

foreach (['../logging.php', '../managedPath.php', '../runtime/commands.php', 'quota.php', '../../configBackups.php', '../../runtime.php'] as $relativePath) {
    require_once __DIR__.'/'.$relativePath;
}

/**
 * Apply hostname overrides provided by the installer.
 */
function pmssApplyHostnameConfig(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    if (!pmssEnvValueIsFalsey(getenv('PMSS_SKIP_HOSTNAME'))) {
        $log('[SKIP] Hostname configuration skipped via PMSS_SKIP_HOSTNAME');
        return;
    }

    if (($hostname = trim((string) getenv('PMSS_HOSTNAME'))) === '') {
        $log('[SKIP] No hostname override provided');
        return;
    }

    $hasHostnamectl = pmssCommandPath('hostnamectl') !== '';
    runStep(
        $hasHostnamectl ? 'Setting hostname via hostnamectl' : 'Setting hostname',
        $hasHostnamectl
            ? pmssBuildCommand('hostnamectl', ['set-hostname', $hostname])
            : pmssBuildCommand('hostname', [$hostname])
    );

    if (pmssHostnameRead() === $hostname) {
        $log('[SKIP] /etc/hostname already set to '.$hostname);
        return;
    }

    if (!pmssWriteManagedPathFile('/etc/hostname', $hostname.PHP_EOL, 'hostname', $log, 'root', 'root')) {
        return;
    }
    $log('Updated /etc/hostname to '.$hostname);
}

/**
 * Ensure quota options exist for the requested mount and remount it.
 */
function pmssConfigureQuotaMount(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    if (!pmssEnvValueIsFalsey(getenv('PMSS_SKIP_QUOTA'))) {
        $log('[SKIP] Quota configuration skipped via PMSS_SKIP_QUOTA');
        return;
    }

    $mount = trim(pmssResolvePathFromEnv('PMSS_QUOTA_MOUNT', '/home'));
    pmssEnsureQuotaOptions($mount, null, $log);
    if (!is_dir($mount)) {
        $log('[WARN] Skipping remount for '.$mount.' (mount path not found)');
        return;
    }

    runStep('Remounting '.$mount.' to refresh quota options', sprintf('mount -o remount %s', escapeshellarg($mount)));
    pmssWarnUnexpectedQuotaFiles($mount, $log);
}

/**
 * Normalize sshd template content for older parsers that reject modern
 * list-append syntax or renamed key directives.
 */
function pmssSshdLegacyParserTemplateNormalize(string $config): string
{
    if (!is_array($lines = preg_split("/\r?\n/", $config))) {
        return $config;
    }
    $legacyDirectives = [
        'Ciphers' => 'Ciphers aes128-gcm@openssh.com,aes256-gcm@openssh.com,chacha20-poly1305@openssh.com,aes128-ctr,aes192-ctr,aes256-ctr,aes128-cbc,aes192-cbc,aes256-cbc',
        'KexAlgorithms' => 'KexAlgorithms curve25519-sha256@libssh.org,ecdh-sha2-nistp256,ecdh-sha2-nistp384,ecdh-sha2-nistp521,diffie-hellman-group-exchange-sha256,diffie-hellman-group-exchange-sha1,diffie-hellman-group14-sha1,diffie-hellman-group1-sha1',
        'MACs' => 'MACs hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com,umac-128-etm@openssh.com,hmac-sha2-512,hmac-sha2-256,hmac-sha1,hmac-sha1-96,hmac-md5,hmac-md5-96,hmac-ripemd160,hmac-ripemd160@openssh.com',
    ];
    $present = array_fill_keys(array_keys($legacyDirectives), false);
    $updatedLines = [];
    foreach ($lines as $line) {
        foreach ($legacyDirectives as $directive => $replacement) {
            if (preg_match('/^\s*'.preg_quote($directive, '/').'\s+/i', $line) === 1) {
                $updatedLines[] = $replacement;
                $present[$directive] = true;
                continue 2;
            }
        }
        if (preg_match('/^\s*(Host[kK]eyAlgorithms|PubkeyAcceptedKeyTypes)\s+/', $line) === 1) {
            $updatedLines[] = '# '.$line;
            continue;
        }
        $updatedLines[] = $line;
    }
    foreach ($present as $directive => $seen) {
        if (!$seen) {
            $updatedLines[] = $legacyDirectives[$directive];
        }
    }
    $updated = implode("\n", $updatedLines);
    return ($updated !== '' && substr($updated, -1) !== "\n") ? $updated."\n" : $updated;
}

/**
 * Refresh rc.local, systemd, and sshd configuration templates.
 */
function pmssApplyRuntimeTemplates(): void
{
    foreach ([
        ['Updating rc.local template', 'cp /etc/seedbox/config/template.rc.local /etc/rc.local'],
        ['Setting rc.local ownership', 'chown root:root /etc/rc.local'],
        ['Setting rc.local permissions', 'chmod 750 /etc/rc.local'],
        ['Executing rc.local to apply runtime tweaks', 'nohup /etc/rc.local >> /dev/null 2>&1'],
        ['Installing systemd system.conf template', 'cp /etc/seedbox/config/template.systemd.system.conf /etc/systemd/system.conf'],
        ['Setting permissions on systemd system.conf', 'chmod 644 /etc/systemd/system.conf'],
        ['Reexecuting systemd to pick up configuration', '/usr/bin/systemctl daemon-reexec'],
    ] as $action) { runStep(...$action); }

    pmssBackupCriticalConfig('sshd', '/etc/ssh/sshd_config');
    foreach ([
        ['Installing sshd configuration template', 'cp /etc/seedbox/config/template.sshd_config /etc/ssh/sshd_config'],
        ['Setting sshd_config permissions', 'chmod 644 /etc/ssh/sshd_config'],
    ] as $action) { runStep(...$action); }

    $validateRc = runStep('Validating sshd configuration syntax', 'sshd -t');
    if ($validateRc !== 0) {
        $sshdConfig = '/etc/ssh/sshd_config';
        if (!is_string($config = @file_get_contents($sshdConfig))) {
            logMessage('[WARN] Cannot read sshd_config for legacy-parser normalization');
        } else {
            $updated = pmssSshdLegacyParserTemplateNormalize($config);
            if ($updated === $config) {
                logMessage('[INFO] sshd legacy-parser normalization made no changes');
            } else {
                logMessage('[WARN] Applying sshd legacy-parser compatibility fallback');
                pmssBackupCriticalConfig('sshd', $sshdConfig);
                if (!pmssWriteManagedPathFile($sshdConfig, $updated, 'sshd config legacy parser fallback', 'logMessage', 'root', 'root')) {
                    logMessage('[ERR] Failed to write sshd legacy-parser fallback configuration');
                }
            }
        }
        $validateRc = runStep('Re-validating sshd syntax after legacy-parser fallback', 'sshd -t');
        if ($validateRc !== 0) {
            logMessage('[ERR] sshd validation failed after legacy-parser fallback; skipping sshd restart');
            return;
        }
    }

    runStep('Restarting sshd to load updated configuration', '/usr/bin/systemctl restart sshd');
}

/**
 * Guarantee sshd honours per-user AuthorizedKeysFile entries.
 * #TODO(sshd-template): migrate this into the sshd_config template flow and
 * drop once userPermissions + template cover permissions and directives.
 */
function pmssEnsureAuthorizedKeysDirective(): void
{
    // #TODO Add tests for directive insertion to ensure idempotence and
    //       safe in-place updates of sshd_config.
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        logMessage('[SKIP] PMSS_DRY_RUN: skipping sshd AuthorizedKeysFile directive enforcement');
        return;
    }
    $sshdConfig = '/etc/ssh/sshd_config';
    if (!is_string($config = @file_get_contents($sshdConfig))) {
        return;
    }
    logMessage('[START] Ensuring sshd AuthorizedKeysFile directive is enabled');
    if ($config === ($updated = str_replace('#AuthorizedKeysFile', 'AuthorizedKeysFile', $config))) {
        return;
    }

    echo "# Allowing SSH Key based authentication.\n";
    pmssBackupCriticalConfig('sshd', $sshdConfig);
    pmssWriteManagedPathFile('/etc/ssh/pmss.sshd_config', $config, 'sshd backup config', 'logMessage', 'root', 'root');
    if (!pmssWriteManagedPathFile($sshdConfig, $updated, 'sshd config', 'logMessage', 'root', 'root')) {
        return;
    }
    runStep('Restarting sshd service after config update', '/etc/init.d/ssh restart');
}
