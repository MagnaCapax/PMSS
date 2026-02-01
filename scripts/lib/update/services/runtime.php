<?php
/**
 * Runtime template management for system services.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../../configBackups.php';

if (!function_exists('pmssApplyRuntimeTemplates')) {
    /**
     * Refresh rc.local, systemd, and sshd configuration templates.
     */
    function pmssApplyRuntimeTemplates(): void
    {
        $rcLocal = '/etc/rc.local';
        runStep('Updating rc.local template', 'cp /etc/seedbox/config/template.rc.local '.$rcLocal);
        runStep('Setting rc.local ownership', 'chown root:root '.$rcLocal);
        runStep('Setting rc.local permissions', 'chmod 750 '.$rcLocal);
        runStep('Executing rc.local to apply runtime tweaks', 'nohup '.$rcLocal.' >> /dev/null 2>&1');

        $systemdConf = '/etc/systemd/system.conf';
        runStep('Installing systemd system.conf template', 'cp /etc/seedbox/config/template.systemd.system.conf '.$systemdConf);
        runStep('Setting permissions on systemd system.conf', 'chmod 644 '.$systemdConf);
        runStep('Reexecuting systemd to pick up configuration', '/usr/bin/systemctl daemon-reexec');

        $sshdConfig = '/etc/ssh/sshd_config';
        pmssBackupCriticalConfig('sshd', $sshdConfig);
        runStep('Installing sshd configuration template', 'cp /etc/seedbox/config/template.sshd_config '.$sshdConfig);
        runStep('Setting sshd_config permissions', 'chmod 644 '.$sshdConfig);
        runStep('Restarting sshd to load updated configuration', '/usr/bin/systemctl restart sshd');
    }
}

if (!function_exists('pmssEnsureAuthorizedKeysDirective')) {
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
        $backupPath = '/etc/ssh/pmss.sshd_config';
        $config = @file_get_contents($sshdConfig);
        if ($config === false) {
            return;
        }
        logMessage('[START] Ensuring sshd AuthorizedKeysFile directive is enabled');
        $updated = str_replace('#AuthorizedKeysFile', 'AuthorizedKeysFile', $config);
        if ($config === $updated) {
            return;
        }

        echo "# Allowing SSH Key based authentication.\n";
        pmssBackupCriticalConfig('sshd', $sshdConfig);
        @copy($sshdConfig, $backupPath);
        file_put_contents($sshdConfig, $updated);
        runStep('Restarting sshd service after config update', '/etc/init.d/ssh restart');
    }
}
