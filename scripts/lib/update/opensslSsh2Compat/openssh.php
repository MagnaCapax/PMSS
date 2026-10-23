<?php
/** OpenSSL/OpenSSH compatibility OpenSSH heal and downgrade policies. @license GPL-3.0-only */

/**
 * Reinstall OpenSSH when the Debian 12 libssl cascade left sshd missing or deleted.
 *
 * Uses dpkg-direct installation, preserves sshd_config, validates with
 * sshd -t, and restarts ssh only when the listener is on a deleted binary.
 */
function pmssHealOpensshServerIfMissing(?int $distroVersion = null): void
{
    if (!pmssOpenSslSsh2CompatCanMutate(__FUNCTION__, $distroVersion)) {
        return;
    }

    // Health is functional: repair only when the binary is missing or the
    // listener still runs from a deleted executable.
    $sshdFile = is_file('/usr/sbin/sshd');

    $sshdExeDeleted = pmssOpenSslSsh2CompatSshdExeDeleted();

    if ($sshdFile && !$sshdExeDeleted) {
        logMessage('[SKIP] pmssHealOpensshServerIfMissing: sshd functionally healthy (binary on disk + PID not on deleted exe)');
        return;
    }

    logMessage(sprintf(
        '[INFO] pmssHealOpensshServerIfMissing: cascade-residual state detected — binary_on_disk=%s pid_on_deleted_exe=%s',
        $sshdFile ? 'yes' : 'no',
        $sshdExeDeleted ? 'yes' : 'no'
    ));

    pmssOpenSslSsh2CompatOpenSshInstall(false);

    pmssOpenSslSsh2CompatRedeploySshdConfigTemplate(
        'cascade-heal',
        'pre-heal',
        'libssh2 1.4.3 compat lines',
        __FUNCTION__
    );

    $holdRc = runStep(
        'Holding openssh-server/client/sftp to prevent re-removal',
        pmssAptDpkgEnvPrefix().' apt-mark hold '.pmssOpenSslSsh2CompatOpenSshPackages()
    );
    if ($holdRc !== 0) {
        logMessage('[ERROR] pmssHealOpensshServerIfMissing: apt-mark hold failed');
        throw new RuntimeException('pmssHealOpensshServerIfMissing: hold failed');
    }

    // Sanitize legacy hmac-ripemd160 from sshd_config (removed in OpenSSH 9.2).
    if (file_exists('/etc/ssh/sshd_config')) {
        $cfg = (string) @file_get_contents('/etc/ssh/sshd_config');
        if (strpos($cfg, 'hmac-ripemd160') !== false) {
            runStep('Stripping legacy hmac-ripemd160 from sshd_config (removed in OpenSSH 9.2)',
                'sed -i \'s/,hmac-ripemd160,hmac-ripemd160@openssh.com//g; s/,hmac-ripemd160//g; s/hmac-ripemd160@openssh.com,//g; s/hmac-ripemd160,//g\' /etc/ssh/sshd_config');
        }
    }

    // /run/sshd is a tmpfs path cleared on reboot; openssh postinst normally
    // creates it but a non-interactive dpkg --configure path may skip that.
    if (!is_dir('/run/sshd')) {
        @mkdir('/run/sshd', 0755, true);
    }

    pmssOpenSslSsh2CompatVerifySshdConfig(__FUNCTION__, 'after install', 'sshd -t failed after install');

    if ($sshdExeDeleted) {
        // Live sshd is on a deleted binary — restart so the new on-disk binary
        // becomes the running process. This kills any active SSH session, so
        // we ONLY do it when the deleted-exe state was actually detected.
        pmssOpenSslSsh2CompatRestartSshAndVerify(
            'Restarting ssh.service to swap deleted-binary sshd for the freshly-installed one',
            'systemctl daemon-reload && systemctl enable ssh && systemctl restart ssh',
            __FUNCTION__,
            'after restart',
            'sshd not listening after restart'
        );
        logMessage('[OK] pmssHealOpensshServerIfMissing: openssh-server reinstalled, ssh.service restarted, port 22 listening');
    } else {
        // Binary was missing on disk but no live PID was running on a deleted
        // exe. Enable ssh.service so the freshly-installed binary takes effect
        // at next boot without dropping anything that's currently working.
        runStep('Enabling ssh.service for next boot',
            'systemctl daemon-reload && systemctl enable ssh');
        logMessage('[OK] pmssHealOpensshServerIfMissing: openssh-server reinstalled + held + enabled (no live deleted-exe PID to swap)');
    }
}

/**
 * Downgrade OpenSSH when held libssl3 3.0.17 makes newer OpenSSH unconfigurable.
 *
 * Canonical Debian 12 state is OpenSSH <= deb12u7 with libssl3/openssl held;
 * the downgrade uses dpkg-direct and validates sshd before restart. Refs #436.
 */
function pmssEnsureOpensshCompatibleWithHeldLibssl3(?int $distroVersion = null): void
{
    $targetOpenssh = PMSS_OPENSSL_SSH2_OPENSSH_TARGET;

    if (!pmssOpenSslSsh2CompatCanMutate(__FUNCTION__, $distroVersion)) {
        return;
    }

    // Precondition: libssl3 must be held at 3.0.17. If not, this function's
    // assumptions don't hold and we no-op (the libssl3 healer earlier in
    // update-step2 should have converged this state; if it didn't, this
    // function shouldn't compensate by guessing).
    $libsslVer = trim((string) @shell_exec('dpkg-query -W -f=\'${Version}\' libssl3 2>/dev/null'));
    if (strpos($libsslVer, '3.0.17') !== 0) {
        logMessage('[SKIP] pmssEnsureOpensshCompatibleWithHeldLibssl3: libssl3 not at 3.0.17 (got: '.$libsslVer.') — precondition not met');
        return;
    }

    $opensshVer = trim((string) @shell_exec('dpkg-query -W -f=\'${Version}\' openssh-server 2>/dev/null'));
    if ($opensshVer === '') {
        logMessage('[SKIP] pmssEnsureOpensshCompatibleWithHeldLibssl3: openssh-server not installed');
        return;
    }

    if (!pmssOpenSslSsh2CompatVersionCompare($opensshVer, 'gt', $targetOpenssh)) {
        logMessage('[SKIP] pmssEnsureOpensshCompatibleWithHeldLibssl3: openssh-server already at '.$opensshVer.' (<= target '.$targetOpenssh.')');
        return;
    }

    logMessage('[INFO] pmssEnsureOpensshCompatibleWithHeldLibssl3: openssh-server at '.$opensshVer.' is newer than libssl3-3.0.17-compatible target '.$targetOpenssh.' — downgrading via dpkg-direct');

    pmssOpenSslSsh2CompatOpenSshInstall(true);

    pmssOpenSslSsh2CompatRedeploySshdConfigTemplate('post-downgrade', 'pre-downgrade', 'post-downgrade');

    pmssOpenSslSsh2CompatVerifySshdConfig(__FUNCTION__, 'after downgrade', 'sshd -t failed');

    // Restart ssh.service so the downgraded sshd takes over.
    pmssOpenSslSsh2CompatRestartSshAndVerify(
        'Restarting ssh.service to load downgraded openssh-server',
        'systemctl daemon-reload && systemctl restart ssh',
        __FUNCTION__,
        'after downgrade-restart',
        'sshd not listening after restart'
    );

    $newVer = trim((string) @shell_exec('dpkg-query -W -f=\'${Version}\' openssh-server 2>/dev/null'));
    logMessage('[OK] pmssEnsureOpensshCompatibleWithHeldLibssl3: openssh-server downgraded from '.$opensshVer.' to '.$newVer.' (libssl3-3.0.17-compatible canonical state)');
}
