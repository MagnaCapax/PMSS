<?php
/**
 * Baseline service helpers for installer bootstrap and runtime refresh.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

foreach (['../logging.php', '../managedPath.php', '../runtime/commands.php', 'quota.php',
    '../../configBackups.php', '../../runtime.php', 'ioPlacement.php',
    'hostname.php', 'sshdConfig.php', 'sshd.php', 'oomImmunity.php'] as $relativePath) {
    require_once __DIR__.'/'.$relativePath;
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
    ] as $action) { runStep(...$action); }

    pmssCgroupIoPlacementConfigure(pmssCgroupPolicyLoad(), pmssCgroupMode());
    runStep('Reexecuting systemd to pick up configuration', '/usr/bin/systemctl daemon-reexec');

    pmssEnsureSshdStarvationDropin();
    pmssResetInheritedOomImmunity();

    pmssBackupCriticalConfig('sshd', '/etc/ssh/sshd_config');
    foreach ([
        ['Installing sshd configuration template', 'cp /etc/seedbox/config/template.sshd_config /etc/ssh/sshd_config'],
        ['Setting sshd_config permissions', 'chmod 644 /etc/ssh/sshd_config'],
    ] as $action) { runStep(...$action); }

    $sshdConfig = '/etc/ssh/sshd_config';
    if (!pmssEnvFlagEnabled('PMSS_DRY_RUN') && is_string($config = @file_get_contents($sshdConfig))) {
        if (($updated = pmssSshdAuthorizedKeysDirectiveNormalize($config)) !== $config) {
            logMessage('[INFO] Enabling sshd AuthorizedKeysFile directive from runtime template flow');
            pmssSshdConfigWriteUpdated($sshdConfig, $config, $updated, 'sshd config', true);
        }
    }

    if (!pmssSshdValidateConfigWithLegacyFallback($sshdConfig, pmssSshdValidationCommand())) {
        return;
    }

    runStep('Restarting sshd to load updated configuration', '/usr/bin/systemctl restart sshd');
}
