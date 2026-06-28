<?php
/**
 * libssl3/openssl/openssh PECL-ssh2 compatibility convergence for update-step2.
 *
 * Extracted from environment.php on 2026-05-24 (these three #436 functions were
 * >50% of that file). Behaviour-preserving move — no logic change. Requires
 * environment.php for the private-temp-dir helpers the heal/ensure paths use.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/environment.php';

const PMSS_OPENSSL_SSH2_LIBSSL_TARGET = '3.0.17-1~deb12u2';
const PMSS_OPENSSL_SSH2_OPENSSH_TARGET = '1:9.2p1-2+deb12u7';
const PMSS_OPENSSL_SSH2_OPENSSH_PACKAGES = ['openssh-server', 'openssh-client', 'openssh-sftp-server'];

// Shared guard: these repairs are Debian 12-only and must honor dry-run mode.
function pmssOpenSslSsh2CompatCanMutate(string $functionName, ?int $distroVersion): bool
{
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        logMessage('[DRY-RUN] '.$functionName.': skipping all mutations');
        return false;
    }

    $effectiveVersion = pmssDistroVersionFromEnv($distroVersion);
    if ($effectiveVersion !== 12) {
        logMessage('[SKIP] '.$functionName.': not Debian 12 (detected: '.$effectiveVersion.')');
        return false;
    }

    return true;
}

function pmssOpenSslSsh2CompatVersionQueryCommand(string $package): string
{
    return 'dpkg-query -W -f='.escapeshellarg('${Version}').' '.escapeshellarg($package).' 2>/dev/null';
}

// Use dpkg for version ordering so epoch/suffix comparisons stay correct.
function pmssOpenSslSsh2CompatVersionCompare(string $left, string $operator, string $right): bool
{
    $rc = 1;
    @exec(
        'dpkg --compare-versions '
        .escapeshellarg($left).' '
        .escapeshellarg($operator).' '
        .escapeshellarg($right),
        $unused,
        $rc
    );

    return $rc === 0;
}

function pmssOpenSslSsh2CompatPackageHeld(string $heldList, string $package): bool
{
    return preg_match('/(^|\R)'.preg_quote($package, '/').'($|\R)/', $heldList) === 1;
}

// Return space-separated package=version specs for apt commands.
function pmssOpenSslSsh2CompatPackageVersionSpecs(array $packages, string $version, bool $escapeVersion = false): string
{
    return implode(' ', array_map(static function (string $package) use ($version, $escapeVersion): string {
        return $package.'='.($escapeVersion ? escapeshellarg($version) : $version);
    }, $packages));
}

function pmssOpenSslSsh2CompatOpenSshPackages(?string $version = null, bool $escapeVersion = false): string
{
    return $version === null
        ? implode(' ', PMSS_OPENSSL_SSH2_OPENSSH_PACKAGES)
        : pmssOpenSslSsh2CompatPackageVersionSpecs(PMSS_OPENSSL_SSH2_OPENSSH_PACKAGES, $version, $escapeVersion);
}

function pmssOpenSslSsh2CompatDownloadedDebs(string $tmpDir): array
{
    $debs = glob($tmpDir.'/*.deb') ?: [];
    sort($debs);

    return $debs;
}

function pmssOpenSslSsh2CompatDebsByPrefix(array $debs, string $prefix): array
{
    return array_values(array_filter($debs, static function (string $deb) use ($prefix): bool {
        return strpos(basename($deb), $prefix) === 0;
    }));
}

function pmssOpenSslSsh2CompatFirstDebByPrefix(array $debs, string $prefix): string
{
    foreach ($debs as $deb) {
        if (strpos(basename($deb), $prefix) === 0) {
            return $deb;
        }
    }

    return '';
}

function pmssOpenSslSsh2CompatDpkgInstallCommand(array $debs): string
{
    return dpkgCmd('--force-confdef --force-confold -i '.implode(' ', array_map('escapeshellarg', $debs)));
}

// Re-deploy the PMSS sshd_config template after package repair paths touch OpenSSH.
function pmssOpenSslSsh2CompatRedeploySshdConfigTemplate(
    string $backupLabel,
    string $backupPrefix,
    string $redeployLabel,
    ?string $missingWarningFunction = null
): void {
    $tmpl = '/etc/seedbox/config/template.sshd_config';
    $live = '/etc/ssh/sshd_config';
    if (file_exists($tmpl)) {
        runStep('Backing up sshd_config before re-deploying PMSS template ('.$backupLabel.')',
            'cp '.escapeshellarg($live).' '.escapeshellarg($live.'.'.$backupPrefix.'-'.date('Ymd-His')));
        runStep('Re-deploying PMSS sshd_config template ('.$redeployLabel.')',
            'cp '.escapeshellarg($tmpl).' '.escapeshellarg($live));
        runStep('Setting sshd_config permissions after template re-deploy',
            'chmod 644 '.escapeshellarg($live));
    } elseif ($missingWarningFunction !== null) {
        logMessage('[WARN] '.$missingWarningFunction.': '.$tmpl.' missing on host; cannot re-deploy template — sshd_config may be at package default (no HostkeyAlgorithms +ssh-rsa)');
    }
}

function pmssOpenSslSsh2CompatVerifySshdConfig(string $functionName, string $logContext, string $exceptionSuffix): void
{
    $sshdT = 1;
    @exec('/usr/sbin/sshd -t 2>&1', $sshdTOut, $sshdT);
    if ($sshdT !== 0) {
        logMessage('[ERROR] '.$functionName.': sshd -t failed '.$logContext.': '.implode(' '.chr(124).' ', array_slice($sshdTOut, 0, 5)));
        throw new RuntimeException($functionName.': '.$exceptionSuffix);
    }
}

function pmssOpenSslSsh2CompatPort22Listening(): bool
{
    return trim((string) @shell_exec("ss -tln 2>/dev/null | grep -q ':22 ' && echo yes || echo no")) === 'yes';
}

// Restart ssh.service and fail closed when port 22 does not return.
function pmssOpenSslSsh2CompatRestartSshAndVerify(
    string $description,
    string $command,
    string $functionName,
    string $portLogContext,
    string $exceptionSuffix
): void {
    runStep($description, $command);
    sleep(2);
    if (!pmssOpenSslSsh2CompatPort22Listening()) {
        logMessage('[ERROR] '.$functionName.': port 22 not listening '.$portLogContext);
        throw new RuntimeException($functionName.': '.$exceptionSuffix);
    }
}

/**
     * Hold Debian 12 libssl3/openssl at the PECL ssh2-compatible version.
     *
     * When downgrading, converge libssl3, openssl, and the OpenSSH trio in one
     * apt transaction so openssh is downgraded instead of removed. Refs #436/#585.
     */
    function pmssHoldLibssl3ForPeclSsh2Compat(?int $distroVersion = null): void
    {
        $targetVersion = PMSS_OPENSSL_SSH2_LIBSSL_TARGET;

        if (!pmssOpenSslSsh2CompatCanMutate(__FUNCTION__, $distroVersion)) {
            return;
        }

        $versionQuery = pmssOpenSslSsh2CompatVersionQueryCommand('libssl3');
        $rawVer = trim((string) @shell_exec($versionQuery));
        if ($rawVer === '') {
            logMessage('[SKIP] pmssHoldLibssl3ForPeclSsh2Compat: libssl3 is not installed');
            return;
        }

        $heldList   = (string) @shell_exec('apt-mark showhold 2>/dev/null');
        $libsslHeld = pmssOpenSslSsh2CompatPackageHeld($heldList, 'libssl3');
        $opensslHeld = pmssOpenSslSsh2CompatPackageHeld($heldList, 'openssl');

        $needsDowngrade = pmssOpenSslSsh2CompatVersionCompare($rawVer, 'gt', $targetVersion);
        $needsUpgrade   = pmssOpenSslSsh2CompatVersionCompare($rawVer, 'lt', $targetVersion);
        $atTarget       = pmssOpenSslSsh2CompatVersionCompare($rawVer, 'eq', $targetVersion);
        if ($atTarget && $libsslHeld && $opensslHeld) {
            logMessage('[SKIP] pmssHoldLibssl3ForPeclSsh2Compat: already at '.$targetVersion.' with libssl3+openssl held');
            return;
        }

        $downgraded = false;
        if ($needsUpgrade) {
            $upgradeCmd = aptCmd('install -y libssl3='.$targetVersion.' openssl='.$targetVersion);
            $upgradeRc  = runStep('Upgrading libssl3/openssl to '.$targetVersion.' (convergence from older version)', $upgradeCmd);
            if ($upgradeRc !== 0) {
                logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: upgrade to '.$targetVersion.' failed');
                throw new RuntimeException('Unable to upgrade libssl3/openssl to '.$targetVersion);
            }
        } elseif ($needsDowngrade) {
            $compatSet = pmssOpenSslSsh2CompatPackageVersionSpecs(['libssl3', 'openssl'], $targetVersion)
                .' '.pmssOpenSslSsh2CompatOpenSshPackages(PMSS_OPENSSL_SSH2_OPENSSH_TARGET);
            $downgradeRc = runStep(
                'Converging libssl3/openssl/openssh to the 3.0.17-compatible set',
                aptCmd('install -y --allow-downgrades --allow-change-held-packages '.$compatSet)
            );
            if ($downgradeRc !== 0) {
                logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: full-set convergence to '.$targetVersion.' failed');
                throw new RuntimeException('Unable to converge libssl3/openssl/openssh to the '.$targetVersion.'-compatible set');
            }

            $downgraded = true;
        }

        $postVer = trim((string) @shell_exec($versionQuery));
        if ($postVer === '') {
            logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: unable to read libssl3 version after convergence');
            throw new RuntimeException('Unable to read libssl3 version after convergence');
        }
        if (!pmssOpenSslSsh2CompatVersionCompare($postVer, 'eq', $targetVersion)) {
            logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: libssl3 remains at '.$postVer.' after convergence attempt');
            throw new RuntimeException('libssl3 convergence failed: expected '.$targetVersion.', got '.$postVer);
        }

        $holdRc = runStep('Holding libssl3 + openssl at '.$targetVersion, 'apt-mark hold libssl3 openssl');
        if ($holdRc !== 0) {
            logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: apt-mark hold failed');
            throw new RuntimeException('Unable to hold libssl3/openssl after convergence');
        }

        if ($downgraded) {
            if (pmssOpenSslSsh2CompatPort22Listening()) {
                runStep('Restarting sshd after libssl3 downgrade', '/usr/bin/systemctl restart ssh');
            } else {
                logMessage('[SKIP] pmssHoldLibssl3ForPeclSsh2Compat: SSH port 22 not listening; skipping restart');
            }
        }
    }

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

        // Prefer ssh.service MainPID so old per-session sshd forks do not cause
        // false positives after a healthy restart. Without MainPID, require at
        // least one sshd process with a non-deleted executable.
        $sshdExeDeleted = false;
        $mainPidRaw = trim((string) @shell_exec("systemctl show -p MainPID --value ssh 2>/dev/null"));
        if ($mainPidRaw !== '' && ctype_digit($mainPidRaw) && (int) $mainPidRaw > 0) {
            $exeLink = @readlink('/proc/'.$mainPidRaw.'/exe');
            if ($exeLink !== false && strpos($exeLink, '(deleted)') !== false) {
                $sshdExeDeleted = true;
            }
        } else {
            // Fallback path — no MainPID. Inspect every sshd PID; if NONE has a
            // clean exe link, it's the time-bomb state.
            $anyClean   = false;
            $sawAnyPid  = false;
            $pidsRaw    = trim((string) @shell_exec('pgrep -x sshd 2>/dev/null'));
            if ($pidsRaw !== '') {
                foreach (preg_split('/\s+/', $pidsRaw, -1, PREG_SPLIT_NO_EMPTY) as $pid) {
                    if (!ctype_digit($pid)) continue;
                    $sawAnyPid = true;
                    $exeLink = @readlink('/proc/'.$pid.'/exe');
                    if ($exeLink !== false && strpos($exeLink, '(deleted)') === false) {
                        $anyClean = true;
                        break;
                    }
                }
            }
            $sshdExeDeleted = ($sawAnyPid && !$anyClean);
        }

        if ($sshdFile && !$sshdExeDeleted) {
            logMessage('[SKIP] pmssHealOpensshServerIfMissing: sshd functionally healthy (binary on disk + PID not on deleted exe)');
            return;
        }

        logMessage(sprintf(
            '[INFO] pmssHealOpensshServerIfMissing: cascade-residual state detected — binary_on_disk=%s pid_on_deleted_exe=%s',
            $sshdFile ? 'yes' : 'no',
            $sshdExeDeleted ? 'yes' : 'no'
        ));

        $tmpDir = pmssCreatePrivateTempDir('pmss-openssh-');
        if ($tmpDir === null) {
            logMessage('[ERROR] pmssHealOpensshServerIfMissing: cannot create temporary directory for dpkg-direct heal');
            throw new RuntimeException('pmssHealOpensshServerIfMissing: temp dir creation failed');
        }

        $downloadRc = runStep(
            'Downloading openssh-server/client/sftp + runit-helper for dpkg-direct heal',
            'cd '.escapeshellarg($tmpDir).' && '
            .pmssAptDpkgEnvPrefix().' apt-get download openssh-server openssh-client openssh-sftp-server runit-helper 2>&1'
        );
        if ($downloadRc !== 0) {
            pmssRemovePrivateTempDir($tmpDir, 'pmss-openssh-', 'Cleaning openssh-direct download cache');
            logMessage('[ERROR] pmssHealOpensshServerIfMissing: apt-get download failed; refusing unsafe fallback');
            throw new RuntimeException('pmssHealOpensshServerIfMissing: download failed');
        }

        $debs = pmssOpenSslSsh2CompatDownloadedDebs($tmpDir);
        if (count($debs) < 3) {
            pmssRemovePrivateTempDir($tmpDir, 'pmss-openssh-', 'Cleaning openssh-direct download cache');
            logMessage('[ERROR] pmssHealOpensshServerIfMissing: expected openssh-* .deb files were not downloaded (got '.count($debs).')');
            throw new RuntimeException('pmssHealOpensshServerIfMissing: incomplete deb set');
        }

        // Install runit-helper FIRST (openssh-server postinst depends on it on recent bookworm).
        $runitDeb = pmssOpenSslSsh2CompatFirstDebByPrefix($debs, 'runit-helper');
        if ($runitDeb !== '') {
            runStep('Installing runit-helper via dpkg-direct (openssh-server dep)',
                dpkgCmd('--force-confdef --force-confold -i '.escapeshellarg($runitDeb)));
        }

        // dpkg-direct avoids apt resolver removal; conf flags preserve PMSS sshd_config.
        $opensshDebs = pmssOpenSslSsh2CompatDebsByPrefix($debs, 'openssh');
        $installRc = runStep(
            'Installing openssh-server/client/sftp via dpkg-direct (cascade-heal, conf-preserve)',
            pmssOpenSslSsh2CompatDpkgInstallCommand($opensshDebs)
        );
        if ($installRc !== 0) {
            // dpkg -i may exit non-zero when libssl3 ABI is older than openssh-server
            // expects; the binaries are still unpacked onto disk and load the held
            // libssl3 at runtime. We continue to the hold + verify step below.
            logMessage('[WARN] pmssHealOpensshServerIfMissing: dpkg -i exited non-zero (likely libssl3 ABI mismatch with held 3.0.17); binary placed on disk — continuing to hold + verify');
        }

        pmssRemovePrivateTempDir($tmpDir, 'pmss-openssh-', 'Cleaning openssh-direct download cache');

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

        $tmpDir = pmssCreatePrivateTempDir('pmss-openssh-downgrade-');
        if ($tmpDir === null) {
            logMessage('[ERROR] pmssEnsureOpensshCompatibleWithHeldLibssl3: cannot create temporary directory');
            throw new RuntimeException('pmssEnsureOpensshCompatibleWithHeldLibssl3: temp dir creation failed');
        }

        // Unhold openssh-* before downgrade so dpkg doesn't refuse (manual holds
        // from emergency recovery are common; canonical state has them unheld).
        runStep('Unholding openssh-* for downgrade (if held)',
            pmssAptDpkgEnvPrefix().' apt-mark unhold '.pmssOpenSslSsh2CompatOpenSshPackages().' 2>/dev/null || true');

        // Download the target version trio.
        $downloadRc = runStep(
            'Downloading openssh-server/client/sftp at '.$targetOpenssh.' for libssl3-3.0.17-compat downgrade',
            'cd '.escapeshellarg($tmpDir).' && '
            .pmssAptDpkgEnvPrefix().' apt-get download '
            .pmssOpenSslSsh2CompatOpenSshPackages($targetOpenssh, true).' 2>&1'
        );
        if ($downloadRc !== 0) {
            pmssRemovePrivateTempDir($tmpDir, 'pmss-openssh-downgrade-', 'Cleaning openssh-downgrade download cache');
            logMessage('[ERROR] pmssEnsureOpensshCompatibleWithHeldLibssl3: apt-get download failed — target version may have aged out of the repo');
            throw new RuntimeException('pmssEnsureOpensshCompatibleWithHeldLibssl3: download failed');
        }

        $debs = pmssOpenSslSsh2CompatDownloadedDebs($tmpDir);
        if (count($debs) < 3) {
            pmssRemovePrivateTempDir($tmpDir, 'pmss-openssh-downgrade-', 'Cleaning openssh-downgrade download cache');
            logMessage('[ERROR] pmssEnsureOpensshCompatibleWithHeldLibssl3: incomplete deb set (got '.count($debs).')');
            throw new RuntimeException('pmssEnsureOpensshCompatibleWithHeldLibssl3: incomplete debs');
        }

        // Install via dpkg-direct with conf-preserve (same conf-handling posture
        // as pmssHealOpensshServerIfMissing — never replace site sshd_config).
        $installRc = runStep(
            'Installing openssh-server/client/sftp '.$targetOpenssh.' via dpkg-direct (conf-preserve, libssl3-3.0.17-compat downgrade)',
            pmssOpenSslSsh2CompatDpkgInstallCommand($debs)
        );
        pmssRemovePrivateTempDir($tmpDir, 'pmss-openssh-downgrade-', 'Cleaning openssh-downgrade download cache');
        if ($installRc !== 0) {
            logMessage('[ERROR] pmssEnsureOpensshCompatibleWithHeldLibssl3: dpkg -i failed during downgrade');
            throw new RuntimeException('pmssEnsureOpensshCompatibleWithHeldLibssl3: dpkg -i failed');
        }

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

/**
 * Build the APT preferences (Pin-Priority) body that pins libssl3/openssl and the
 * OpenSSH trio to the PECL-ssh2-compatible set.
 *
 * Pin-Priority 1001 forces these exact versions even when a downgrade is required,
 * while keeping dependents satisfiable — so apt's resolver DOWNGRADES OpenSSH to the
 * libssl3-3.0.17-compatible deb12u7 instead of REMOVING it. apt-mark hold only blocks
 * automatic upgrades; it does NOT prevent removal during dependency resolution, which
 * is what produced the OpenSSH-removal cascade (openssh deb12u9/u10 Depends
 * libssl3>=3.0.19 vs the held 3.0.17). Pure builder — no side effects. Refs #436/#585.
 */
function pmssOpenSslSsh2CompatAptPinContents(): string
{
    return "# Managed by PMSS update-step2 (opensslSsh2Compat). Do not edit by hand.\n"
        ."# Pins libssl3/openssl + OpenSSH to the PECL-ssh2-compatible set (Debian 12)\n"
        ."# so apt downgrades OpenSSH instead of triggering the libssl3>=3.0.19 removal\n"
        ."# cascade. apt-mark hold is insufficient (blocks upgrades, not removals during\n"
        ."# dependency resolution). Refs #436/#585.\n"
        ."\n"
        ."Package: libssl3 openssl\n"
        ."Pin: version ".PMSS_OPENSSL_SSH2_LIBSSL_TARGET."\n"
        ."Pin-Priority: 1001\n"
        ."\n"
        ."Package: ".pmssOpenSslSsh2CompatOpenSshPackages()."\n"
        ."Pin: version ".PMSS_OPENSSL_SSH2_OPENSSH_TARGET."\n"
        ."Pin-Priority: 1001\n";
}

/**
 * Write the libssl3/openssh APT pin (Debian 12 only).
 *
 * This is the declarative, durable counterpart to the imperative convergence/heal
 * functions above. The pin is enforced by apt's own resolver and lives on disk, so
 * it (a) survives the package removal/reinstall that clobbers apt-mark hold, and
 * (b) is in place BEFORE the early fix-broken / dpkg-selection phases that otherwise
 * resolve the held-libssl3-vs-newer-openssh conflict by REMOVING OpenSSH. It pins to
 * the same versions the convergence functions already force fleet-wide, so it is
 * behaviour-consistent — declarative prevention layered ahead of the imperative heal,
 * which is retained as recovery for already-damaged hosts. Idempotent (rewrites only
 * on content drift). Refs #436/#585.
 */
function pmssWriteLibssl3OpensshAptPin(?int $distroVersion = null): void
{
    if (!pmssOpenSslSsh2CompatCanMutate(__FUNCTION__, $distroVersion)) {
        return;
    }

    $pinPath  = '/etc/apt/preferences.d/pmss-libssl3-openssh.pref';
    $contents = pmssOpenSslSsh2CompatAptPinContents();

    if (is_file($pinPath) && (string) @file_get_contents($pinPath) === $contents) {
        logMessage('[SKIP] pmssWriteLibssl3OpensshAptPin: pin already current at '.$pinPath);
        return;
    }

    $tmpPath = $pinPath.'.pmss-tmp';
    if (@file_put_contents($tmpPath, $contents) === false || !@rename($tmpPath, $pinPath)) {
        @unlink($tmpPath);
        logMessage('[ERROR] pmssWriteLibssl3OpensshAptPin: failed to write '.$pinPath);
        throw new RuntimeException('pmssWriteLibssl3OpensshAptPin: unable to write APT pin');
    }
    @chmod($pinPath, 0644);
    logMessage('[OK] pmssWriteLibssl3OpensshAptPin: wrote APT Pin-Priority 1001 for libssl3/openssl + OpenSSH at '.$pinPath);
}
