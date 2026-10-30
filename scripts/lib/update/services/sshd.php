<?php
/** SSH recovery drop-in and checked legacy-parser fallback. @license GPL-3.0-only */

/**
 * Install ssh.service resource-priority drop-in for recovery access.
 *
 * This is defense-in-depth. User-slice service placement prevents pid-table
 * exhaustion; the sshd drop-in helps keep port 22 responsive under CPU/IO/OOM
 * pressure while an operator recovers the host.
 */
function pmssEnsureSshdStarvationDropin(
    string $dropinDir = '/etc/systemd/system/ssh.service.d',
    string $dropinFile = '',
    string $systemdRuntimeDir = '/run/systemd/system'
): bool {
    $dropinDir = rtrim($dropinDir, '/');
    $dropinFile = $dropinFile !== '' ? $dropinFile : $dropinDir.'/10-pmss-starvation-resistance.conf';
    $template = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config').'/template.ssh.service.pmss-starvation.conf';

    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        runStep('Ensuring ssh.service starvation-resistance drop-in directory', 'install -d -m 0755 '.escapeshellarg($dropinDir));
        runStep('Installing ssh.service starvation-resistance drop-in', sprintf('cp %s %s', escapeshellarg($template), escapeshellarg($dropinFile)));
        runStep('Reloading systemd unit files (ssh starvation resistance)', '/usr/bin/systemctl daemon-reload || true');
        runStep('Validating sshd before restarting for OOM inheritance', pmssSshdValidationCommand());
        runStep('Restarting sshd for normal session OOM scores', '/usr/bin/systemctl try-restart ssh');
        return true;
    }

    if (!is_dir($systemdRuntimeDir)) {
        logMessage('[SKIP] Installing ssh.service starvation-resistance drop-in (systemd unavailable)');
        return true;
    }
    if ($dropinDir === '' || $dropinDir[0] !== '/' || $dropinFile[0] !== '/' || dirname($dropinFile) !== $dropinDir) {
        logMessage('[ERR] Refusing unsafe ssh.service drop-in path: '.$dropinFile);
        return false;
    }
    if (!is_string($content = @file_get_contents($template))) {
        logMessage('[ERR] Missing ssh.service starvation-resistance template: '.$template);
        return false;
    }

    $changed = pmssRefreshManagedPathFile(
        $dropinFile,
        $content,
        'ssh.service starvation-resistance drop-in',
        'logMessage'
    );
    if ($changed) {
        runStep('Reloading systemd unit files (ssh starvation resistance)', '/usr/bin/systemctl daemon-reload || true');
        if (runStep('Validating sshd before restarting for OOM inheritance', pmssSshdValidationCommand()) === 0) {
            runStep('Restarting sshd for normal session OOM scores', '/usr/bin/systemctl try-restart ssh');
        } else {
            logMessage('[WARN] Skipping sshd restart: sshd configuration validation failed');
        }
    }

    return true;
}

/**
 * Validate sshd_config, applying the legacy-parser fallback only on parser errors.
 */
function pmssSshdValidateConfigWithLegacyFallback(string $sshdConfig, string $validationCommand): bool
{
    $validateRc = runStep('Validating sshd configuration syntax', $validationCommand);
    if ($validateRc === 0) return true;
    if ($validateRc === 127) {
        logMessage('[ERR] sshd binary unavailable (rc=127); skipping legacy-parser fallback and sshd restart');
        return false;
    }
    if (!is_string($config = @file_get_contents($sshdConfig))) {
        logMessage('[WARN] Cannot read sshd_config for legacy-parser normalization');
    } elseif (($updated = pmssSshdLegacyParserTemplateNormalize($config, 'logMessage')) === $config) {
        logMessage('[INFO] sshd legacy-parser normalization made no changes');
    } else {
        logMessage('[WARN] Applying sshd legacy-parser compatibility fallback');
        if (!pmssSshdConfigWriteUpdated($sshdConfig, $config, $updated, 'sshd config legacy parser fallback')) {
            logMessage('[ERR] Failed to write sshd legacy-parser fallback configuration');
        }
    }
    if (runStep('Re-validating sshd syntax after legacy-parser fallback', $validationCommand) === 0) return true;
    logMessage('[ERR] sshd validation failed after legacy-parser fallback; skipping sshd restart');
    return false;
}
