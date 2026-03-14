<?php
/**
 * Runtime template management for system services.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../../configBackups.php';

/**
 * Refresh rc.local, systemd, and sshd configuration templates.
 */
function pmssApplyRuntimeTemplates(): void
{
    runStep('Updating rc.local template', 'cp /etc/seedbox/config/template.rc.local /etc/rc.local');
    runStep('Setting rc.local ownership', 'chown root:root /etc/rc.local');
    runStep('Setting rc.local permissions', 'chmod 750 /etc/rc.local');
    runStep('Executing rc.local to apply runtime tweaks', 'nohup /etc/rc.local >> /dev/null 2>&1');
    runStep('Installing systemd system.conf template', 'cp /etc/seedbox/config/template.systemd.system.conf /etc/systemd/system.conf');
    runStep('Setting permissions on systemd system.conf', 'chmod 644 /etc/systemd/system.conf');
    runStep('Reexecuting systemd to pick up configuration', '/usr/bin/systemctl daemon-reexec');

    pmssBackupCriticalConfig('sshd', '/etc/ssh/sshd_config');
    runStep('Installing sshd configuration template', 'cp /etc/seedbox/config/template.sshd_config /etc/ssh/sshd_config');
    runStep('Setting sshd_config permissions', 'chmod 644 /etc/ssh/sshd_config');
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
