<?php
/**
 * Baseline service helpers for installer bootstrap and runtime refresh.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

foreach (['../logging.php', '../runtime/commands.php', 'quota.php', '../../configBackups.php', '../../runtime.php'] as $relativePath) {
    require_once __DIR__.'/'.$relativePath;
}

/**
 * Apply hostname overrides provided by the installer.
 */
function pmssApplyHostnameConfig(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    if (!in_array(strtolower(trim((string) getenv('PMSS_SKIP_HOSTNAME'))), ['', '0', 'false', 'no'], true)) {
        $log('[SKIP] Hostname configuration skipped via PMSS_SKIP_HOSTNAME');
        return;
    }

    if (($hostname = trim((string) getenv('PMSS_HOSTNAME'))) === '') {
        $log('[SKIP] No hostname override provided');
        return;
    }

    $hasHostnamectl = trim((string) @shell_exec('command -v hostnamectl')) !== '';
    runStep(
        $hasHostnamectl ? 'Setting hostname via hostnamectl' : 'Setting hostname',
        $hasHostnamectl
            ? pmssBuildCommand('hostnamectl', ['set-hostname', $hostname])
            : pmssBuildCommand('hostname', [$hostname])
    );

    if (is_string($existing = @file_get_contents('/etc/hostname')) && trim($existing) === $hostname) {
        $log('[SKIP] /etc/hostname already set to '.$hostname);
        return;
    }

    @file_put_contents('/etc/hostname', $hostname.PHP_EOL);
    $log('Updated /etc/hostname to '.$hostname);
}

/**
 * Ensure quota options exist for the requested mount and remount it.
 */
function pmssConfigureQuotaMount(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    if (!in_array(strtolower(trim((string) getenv('PMSS_SKIP_QUOTA'))), ['', '0', 'false', 'no'], true)) {
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
    ] as $action) {
        runStep($action[0], $action[1]);
    }

    pmssBackupCriticalConfig('sshd', '/etc/ssh/sshd_config');
    foreach ([
        ['Installing sshd configuration template', 'cp /etc/seedbox/config/template.sshd_config /etc/ssh/sshd_config'],
        ['Setting sshd_config permissions', 'chmod 644 /etc/ssh/sshd_config'],
        ['Restarting sshd to load updated configuration', '/usr/bin/systemctl restart sshd'],
    ] as $action) {
        runStep($action[0], $action[1]);
    }
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
    if (getenv('PMSS_DRY_RUN') === '1') {
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
    @copy($sshdConfig, '/etc/ssh/pmss.sshd_config');
    file_put_contents($sshdConfig, $updated);
    runStep('Restarting sshd service after config update', '/etc/init.d/ssh restart');
}
