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
    // Default ext4 journal commit interval (60s vs the 5s ext4 default) — batches jbd2 commits to
    // ease RAID5 write-convoy contention on shared seedbox hosts. The remount below applies it live
    // (commit= is remount-able). Skippable via PMSS_SKIP_QUOTA (same gate that guards this whole step).
    pmssEnsureJournalCommitOption($mount, 60, $log);
    // Ensure `nofail` so a boot-time mount failure of this mount leaves the host REACHABLE for
    // remote recovery instead of hanging at an emergency prompt (Phase 5.3 pre-reboot expectation).
    // Boot-only option — no live remount needed, so it is applied before the remount below.
    pmssEnsureMountNofailOption($mount, $log);
    if (!is_dir($mount)) {
        $log('[WARN] Skipping remount for '.$mount.' (mount path not found)');
        return;
    }

    // The remount below applies commit= live. But when the fstab entry carries journaled-quota
    // options (jqfmt=/usrjquota=/grpjquota=) and quota is turned on, the kernel refuses the remount —
    // `mount -o remount` re-reads fstab and tries to re-apply the quota options, and ext4 rejects it:
    //   "EXT4-fs: Cannot change journaled quota options when quota turned on"
    // so the step fails rc=32 on every run and logs a spurious ERR that masks real failures in the
    // update log. commit= and the quota options already take effect at mount/boot time, so skip the
    // doomed, redundant remount when the fstab entry declares journaled quota. (The options live in
    // fstab, not /proc/mounts — ext4 does not echo the journaled-quota options in the live mount
    // string — so detection reads the fstab entry.) Non-journaled-quota mounts remount as before.
    $fstabLines = pmssFstabLinesRead('/etc/fstab', $log, 'quota remount journaled-quota check');
    $fstabEntry = $fstabLines !== null ? pmssFstabMountEntryRead($fstabLines, $mount) : null;
    $fstabOptions = $fstabEntry['columns'][3] ?? '';
    if (preg_match('/(?:^|,)(?:jqfmt=|usrjquota=|grpjquota=)/', $fstabOptions) === 1) {
        $log('[INFO] Skipping remount for '.$mount.' (journaled quota in fstab cannot be re-applied while quota is on; commit/quota options take effect at mount time)');
        pmssWarnUnexpectedQuotaFiles($mount, $log);
        return;
    }

    runStep('Remounting '.$mount.' to refresh quota options', sprintf('mount -o remount %s', escapeshellarg($mount)));
    pmssWarnUnexpectedQuotaFiles($mount, $log);
}

/**
 * Normalize sshd template content for older parsers that reject modern
 * list-append syntax or renamed key directives.
 */
function pmssSshdLegacyParserTemplateNormalize(string $config, ?callable $logger = null): string
{
    if (!is_array($lines = preg_split("/\r?\n/", $config))) {
        return $config;
    }
    $legacyDirectives = [
        'Ciphers' => 'Ciphers aes128-gcm@openssh.com,aes256-gcm@openssh.com,chacha20-poly1305@openssh.com,aes128-ctr,aes192-ctr,aes256-ctr,aes128-cbc,aes192-cbc,aes256-cbc',
        'KexAlgorithms' => 'KexAlgorithms curve25519-sha256@libssh.org,ecdh-sha2-nistp256,ecdh-sha2-nistp384,ecdh-sha2-nistp521,diffie-hellman-group-exchange-sha256,diffie-hellman-group-exchange-sha1,diffie-hellman-group14-sha1,diffie-hellman-group1-sha1',
        'MACs' => 'MACs hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com,umac-128-etm@openssh.com,hmac-sha2-512,hmac-sha2-256,hmac-sha1,hmac-sha1-96,hmac-md5,hmac-md5-96',
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
            if ($logger !== null) {
                $logger('[WARN] Legacy-parser fallback is commenting out security-affecting sshd directive: '.trim($line));
            }
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

/** Normalize commented AuthorizedKeysFile directives into active directives. */
function pmssSshdAuthorizedKeysDirectiveNormalize(string $config): string
{
    return str_replace('#AuthorizedKeysFile', 'AuthorizedKeysFile', $config);
}

/** Persist a mutated sshd_config payload through the guarded managed-path flow. */
function pmssSshdConfigWriteUpdated(
    string $sshdConfig,
    string $config,
    string $updated,
    string $label,
    bool $writeManagedBackupCopy = false,
    string $backupCopyPath = '/etc/ssh/pmss.sshd_config'
): bool {
    pmssBackupCriticalConfig('sshd', $sshdConfig);
    $writeManagedBackupCopy && pmssWriteManagedPathFile($backupCopyPath, $config, 'sshd backup config', 'logMessage', 'root', 'root');
    return pmssWriteManagedPathFile($sshdConfig, $updated, $label, 'logMessage', 'root', 'root');
}

/** Build the sshd syntax validation command without depending on operator PATH. */
function pmssSshdValidationCommand(string $sshdPath = '/usr/sbin/sshd'): string
{
    return !is_executable($sshdPath)
        ? 'sshd -t'
        : ($sshdPath === '/usr/sbin/sshd' ? '/usr/sbin/sshd -t' : pmssBuildCommand($sshdPath, ['-t']));
}

/** Return the PMSS ssh.service starvation-resistance drop-in template path. */
function pmssSshdStarvationDropinTemplatePath(): string
{
    return pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config').'/template.ssh.service.pmss-starvation.conf';
}

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
    $template = pmssSshdStarvationDropinTemplatePath();

    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        runStep('Ensuring ssh.service starvation-resistance drop-in directory', 'install -d -m 0755 '.escapeshellarg($dropinDir));
        runStep('Installing ssh.service starvation-resistance drop-in', sprintf('cp %s %s', escapeshellarg($template), escapeshellarg($dropinFile)));
        runStep('Reloading systemd unit files (ssh starvation resistance)', '/usr/bin/systemctl daemon-reload || true');
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

    // NOTE: pass no custom message options here. pmssManagedPathInstallOptions()
    // lives in an unrelated in-flight managedPath.php change that is NOT on main;
    // depending on it broke update-step2 fleet-wide (undefined function, Refs #579).
    // pmssRefreshManagedPathFile null-coalesces every option, so [] installs the
    // drop-in identically (mode 0644) minus the cosmetic skip/success log strings.
    $changed = pmssRefreshManagedPathFile(
        $dropinFile,
        $content,
        'ssh.service starvation-resistance drop-in',
        'logMessage'
    );
    if ($changed) {
        runStep('Reloading systemd unit files (ssh starvation resistance)', '/usr/bin/systemctl daemon-reload || true');
    }

    return true;
}

/**
 * Validate sshd_config, applying the legacy-parser fallback only on parser errors.
 */
function pmssSshdValidateConfigWithLegacyFallback(string $sshdConfig, string $validationCommand): bool
{
    $validateRc = runStep('Validating sshd configuration syntax', $validationCommand);
    if ($validateRc === 127) {
        logMessage('[ERR] sshd binary unavailable (rc=127); skipping legacy-parser fallback and sshd restart');
        return false;
    }
    if ($validateRc !== 0) {
        if (!is_string($config = @file_get_contents($sshdConfig))) {
            logMessage('[WARN] Cannot read sshd_config for legacy-parser normalization');
        } else {
            $updated = pmssSshdLegacyParserTemplateNormalize($config, 'logMessage');
            if ($updated === $config) {
                logMessage('[INFO] sshd legacy-parser normalization made no changes');
            } else {
                logMessage('[WARN] Applying sshd legacy-parser compatibility fallback');
                if (!pmssSshdConfigWriteUpdated($sshdConfig, $config, $updated, 'sshd config legacy parser fallback')) {
                    logMessage('[ERR] Failed to write sshd legacy-parser fallback configuration');
                }
            }
        }
        $validateRc = runStep('Re-validating sshd syntax after legacy-parser fallback', $validationCommand);
        if ($validateRc !== 0) {
            logMessage('[ERR] sshd validation failed after legacy-parser fallback; skipping sshd restart');
            return false;
        }
    }

    return true;
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

    pmssEnsureSshdStarvationDropin();

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
