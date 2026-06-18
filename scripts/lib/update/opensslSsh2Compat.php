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
/**
     * Converge libssl3 + openssl to 3.0.17-1~deb12u2 for PECL/ssh2 compatibility.
     *
     * Why this exists:
     * - Debian 12.13 ships libssl3 3.0.18/3.0.19 paths that break ssh2_exec()
     *   for legacy PECL/ssh2 + libssh2 1.10.0 callers.
     * - The dpkg baseline replay can overwrite ad-hoc host holds during updates.
     *
     * Safety invariants:
     * - Debian 12 only (Debian 13 uses libssl3t64 and is out of scope).
     * - Downgrade the FULL compatible set in one apt transaction (libssl3 +
     *   openssl + openssh-server/client/sftp-server) so apt downgrades openssh
     *   together instead of removing it (openssh depends on libssl3 >= 3.0.19).
     * - --allow-change-held-packages converges hosts whose libssl3/openssl are
     *   already apt-mark held without an unhold/re-hold dance.
     *
     * Idempotent behavior:
     * - If already at 3.0.17-1~deb12u2 and both packages are held, no-op.
     *
     * Refs #436, #585.
     */
    function pmssHoldLibssl3ForPeclSsh2Compat(?int $distroVersion = null): void
    {
        $targetVersion = '3.0.17-1~deb12u2';
        // openssh-server/client/sftp-server depend on libssl3 >= 3.0.19. Downgrading
        // libssl3 ALONE makes apt REMOVE the openssh trio. Converging the trio to its
        // matching 3.0.17-era version (deb12u7) in the SAME apt transaction makes apt
        // downgrade them together instead. Refs #436, #585.
        $opensshTarget = '1:9.2p1-2+deb12u7';

        if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
            logMessage('[DRY-RUN] pmssHoldLibssl3ForPeclSsh2Compat: skipping all mutations');
            return;
        }

        $effectiveVersion = pmssDistroVersionFromEnv($distroVersion);
        if ($effectiveVersion !== 12) {
            logMessage('[SKIP] pmssHoldLibssl3ForPeclSsh2Compat: not Debian 12 (detected: '.$effectiveVersion.')');
            return;
        }

        $versionQuery = 'dpkg-query -W -f='.escapeshellarg('${Version}').' libssl3 2>/dev/null';
        $rawVer = trim((string) @shell_exec($versionQuery));
        if ($rawVer === '') {
            logMessage('[SKIP] pmssHoldLibssl3ForPeclSsh2Compat: libssl3 is not installed');
            return;
        }

        $compareVersions = static function (string $left, string $operator, string $right): bool {
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
        };

        $heldList   = (string) @shell_exec('apt-mark showhold 2>/dev/null');
        $libsslHeld = preg_match('/(^|\R)libssl3($|\R)/', $heldList) === 1;
        $opensslHeld = preg_match('/(^|\R)openssl($|\R)/', $heldList) === 1;

        $needsDowngrade = $compareVersions($rawVer, 'gt', $targetVersion);
        $needsUpgrade   = $compareVersions($rawVer, 'lt', $targetVersion);
        $atTarget       = $compareVersions($rawVer, 'eq', $targetVersion);
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
            // Converge the FULL libssl3-3.0.17-compatible set in ONE apt transaction so
            // apt's resolver downgrades the openssh trio alongside libssl3 instead of
            // removing it. Verified 2026-05-24 on fact-core via --simulate: this set
            // produces "5 downgraded, 1 to remove (libssl-dev only), 0 not upgraded" —
            // openssh-server is downgraded to deb12u7, NOT removed. This replaces the
            // former simulate-detect + dpkg-direct-download fallback (apt finds deb12u7
            // in bookworm-updates natively). --allow-change-held-packages converges hosts
            // whose libssl3/openssl are already apt-mark held without an unhold/re-hold
            // dance. Refs #436, #585.
            $compatSet = 'libssl3='.$targetVersion.' openssl='.$targetVersion
                .' openssh-server='.$opensshTarget
                .' openssh-client='.$opensshTarget
                .' openssh-sftp-server='.$opensshTarget;
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
        if (!$compareVersions($postVer, 'eq', $targetVersion)) {
            logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: libssl3 remains at '.$postVer.' after convergence attempt');
            throw new RuntimeException('libssl3 convergence failed: expected '.$targetVersion.', got '.$postVer);
        }

        $holdRc = runStep('Holding libssl3 + openssl at '.$targetVersion, 'apt-mark hold libssl3 openssl');
        if ($holdRc !== 0) {
            logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: apt-mark hold failed');
            throw new RuntimeException('Unable to hold libssl3/openssl after convergence');
        }

        if ($downgraded) {
            $sshListening = trim((string) @shell_exec("ss -tln 2>/dev/null | grep -q ':22 ' && echo yes || echo no")) === 'yes';
            if ($sshListening) {
                runStep('Restarting sshd after libssl3 downgrade', '/usr/bin/systemctl restart ssh');
            } else {
                logMessage('[SKIP] pmssHoldLibssl3ForPeclSsh2Compat: SSH port 22 not listening; skipping restart');
            }
        }
    }

    /**
     * Heal openssh-server after the 2026-04-30 libssl3 cascade left dpkg in
     * `rc` state (config-only) or with `/usr/sbin/sshd` removed while a running
     * sshd PID kept a deleted-binary in-memory copy alive. PMSS update on its
     * own only converges libssl3+openssl (the proximate cause) and does not
     * detect or repair the residual openssh-server damage — so cascade victims
     * stay as silent time bombs that die at the next reboot.
     *
     * This function detects three independent signals and reinstalls if any is
     * unhealthy: openssh-server is not in `ii` state, `/usr/sbin/sshd` is
     * missing on disk, or the running sshd PID's `/proc/PID/exe` symlink resolves
     * to a "(deleted)" path.
     *
     * Recovery uses the same dpkg-direct path that the libssl3 healer uses, so
     * the apt resolver cannot re-trigger the cascade. The freshly-installed
     * binary then gets `apt-mark hold` plus sshd_config sanitization (legacy
     * hmac-ripemd160 removed in OpenSSH 9.2) and an explicit /run/sshd tmpfs
     * directory before sshd -t verification. ssh.service is restarted ONLY when
     * the live sshd PID is running on a deleted binary — restarting a healthy
     * sshd would needlessly drop active SSH sessions.
     *
     * Idempotent: a host where sshd is healthy on disk and in memory exits with
     * a single `[SKIP]` log line and zero mutations.
     *
     * Refs #436. Discovered 2026-05-20 during fleet sweep finding 5 silent-time
     * bomb hosts (akelarre, oceanic, stafford, roger, voodoo) all on recent
     * PMSS updates with libssl3 correctly held but openssh-server still absent.
     */
    function pmssHealOpensshServerIfMissing(?int $distroVersion = null): void
    {
        if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
            logMessage('[DRY-RUN] pmssHealOpensshServerIfMissing: skipping all mutations');
            return;
        }

        $effectiveVersion = pmssDistroVersionFromEnv($distroVersion);
        if ($effectiveVersion !== 12) {
            logMessage('[SKIP] pmssHealOpensshServerIfMissing: not Debian 12 (detected: '.$effectiveVersion.')');
            return;
        }

        // Functional health signals — the test is "does sshd actually work" not
        // "does dpkg metadata look pretty." A host with `install ok unpacked`
        // openssh-server but a working on-disk binary and a non-deleted-exe sshd
        // PID is functionally healthy and a re-install of the same version would
        // not change real state (it would just re-fail the postinst). The narrow
        // heal-triggers are the two ways the cascade actually breaks SSH:
        //
        //   (1) binary missing on disk — sshd will fail to start on next reboot
        //   (2) sshd PID is running off a deleted binary — same as (1) but the
        //       fuse will blow at next ssh.service restart instead of next reboot

        // Signal A: on-disk binary present?
        $sshdFile = is_file('/usr/sbin/sshd');

        // Signal B: the SSHD LISTENER process is on a deleted binary?
        //
        // Caveat: `pgrep -x sshd` can return MANY pids — every active SSH session
        // is a per-session sshd fork that survives configured into a child until
        // the user disconnects. When ssh.service is restarted, the parent daemon
        // (PID = ssh.service MainPID) gets the fresh on-disk binary, but the
        // pre-restart per-session forks continue to run on the now-deleted parent
        // inode. That's healthy state — the listener is fresh, the stragglers
        // are short-lived. The condition we actually care about is "the LISTENING
        // daemon is on a deleted binary" — checked via ssh.service MainPID.
        //
        // Fallback: if MainPID is 0 / not running, fall back to checking ANY
        // sshd PID — if NONE has a non-deleted exe, the host is a time bomb.
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

        $debs = glob($tmpDir.'/*.deb') ?: [];
        sort($debs);
        if (count($debs) < 3) {
            pmssRemovePrivateTempDir($tmpDir, 'pmss-openssh-', 'Cleaning openssh-direct download cache');
            logMessage('[ERROR] pmssHealOpensshServerIfMissing: expected openssh-* .deb files were not downloaded (got '.count($debs).')');
            throw new RuntimeException('pmssHealOpensshServerIfMissing: incomplete deb set');
        }

        // Install runit-helper FIRST (openssh-server postinst depends on it on recent bookworm).
        $runitDeb = '';
        foreach ($debs as $deb) {
            if (strpos(basename($deb), 'runit-helper') === 0) {
                $runitDeb = $deb;
                break;
            }
        }
        if ($runitDeb !== '') {
            runStep('Installing runit-helper via dpkg-direct (openssh-server dep)',
                dpkgCmd('--force-confdef --force-confold -i '.escapeshellarg($runitDeb)));
        }

        // Install openssh-server/client/sftp via dpkg-direct so the apt resolver
        // cannot re-trigger the original cascade-removal pattern. --force-conf{def,old}
        // preserves the user's existing /etc/ssh/sshd_config — without these flags,
        // dpkg's default behavior in non-interactive contexts REPLACES the active
        // sshd_config with the package default, wiping the PMSS template's
        // HostkeyAlgorithms +ssh-rsa / PubkeyAcceptedKeyTypes +ssh-rsa lines (libssh2
        // 1.4.3 compat for hallinta/sbautomage). Observed breakage on 5 hosts
        // 2026-05-20 (akelarre/oceanic/stafford/roger/voodoo).
        $opensshDebs = array_values(array_filter($debs, static function ($d) {
            return strpos(basename($d), 'openssh') === 0;
        }));
        $installRc = runStep(
            'Installing openssh-server/client/sftp via dpkg-direct (cascade-heal, conf-preserve)',
            dpkgCmd('--force-confdef --force-confold -i '.implode(' ', array_map('escapeshellarg', $opensshDebs)))
        );
        if ($installRc !== 0) {
            // dpkg -i may exit non-zero when libssl3 ABI is older than openssh-server
            // expects; the binaries are still unpacked onto disk and load the held
            // libssl3 at runtime. We continue to the hold + verify step below.
            logMessage('[WARN] pmssHealOpensshServerIfMissing: dpkg -i exited non-zero (likely libssl3 ABI mismatch with held 3.0.17); binary placed on disk — continuing to hold + verify');
        }

        pmssRemovePrivateTempDir($tmpDir, 'pmss-openssh-', 'Cleaning openssh-direct download cache');

        // Belt-and-suspenders: re-deploy the PMSS sshd_config template if available on
        // the host. --force-conf{def,old} above SHOULD already preserve the user's
        // sshd_config, but dpkg's behavior depends on whether the file matches a known
        // package-version-hash AND on the `rc` (removed-not-purged) state path. The
        // explicit template re-deploy guarantees the PMSS-customized config is active
        // regardless of dpkg's conf-handling decision tree. Mirrors
        // pmssApplyRuntimeTemplates() inline so the heal function does not depend on a
        // later step in the update-step2 flow firing for the sshd restart this function
        // is about to do.
        $tmpl = '/etc/seedbox/config/template.sshd_config';
        $live = '/etc/ssh/sshd_config';
        if (file_exists($tmpl)) {
            runStep('Backing up sshd_config before re-deploying PMSS template (cascade-heal)',
                'cp '.escapeshellarg($live).' '.escapeshellarg($live.'.pre-heal-'.date('Ymd-His')));
            runStep('Re-deploying PMSS sshd_config template (libssh2 1.4.3 compat lines)',
                'cp '.escapeshellarg($tmpl).' '.escapeshellarg($live));
            runStep('Setting sshd_config permissions after template re-deploy',
                'chmod 644 '.escapeshellarg($live));
        } else {
            logMessage('[WARN] pmssHealOpensshServerIfMissing: '.$tmpl.' missing on host; cannot re-deploy template — sshd_config may be at package default (no HostkeyAlgorithms +ssh-rsa)');
        }

        $holdRc = runStep(
            'Holding openssh-server/client/sftp to prevent re-removal',
            pmssAptDpkgEnvPrefix().' apt-mark hold openssh-server openssh-client openssh-sftp-server'
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

        // Verify sshd config parses BEFORE any restart that would drop the live session.
        $sshdT = 1;
        @exec('/usr/sbin/sshd -t 2>&1', $sshdTOut, $sshdT);
        if ($sshdT !== 0) {
            logMessage('[ERROR] pmssHealOpensshServerIfMissing: sshd -t failed after install: '.implode(' | ', array_slice($sshdTOut, 0, 5)));
            throw new RuntimeException('pmssHealOpensshServerIfMissing: sshd -t failed after install');
        }

        if ($sshdExeDeleted) {
            // Live sshd is on a deleted binary — restart so the new on-disk binary
            // becomes the running process. This kills any active SSH session, so
            // we ONLY do it when the deleted-exe state was actually detected.
            runStep('Restarting ssh.service to swap deleted-binary sshd for the freshly-installed one',
                'systemctl daemon-reload && systemctl enable ssh && systemctl restart ssh');
            sleep(2);
            $listening = trim((string) @shell_exec("ss -tln 2>/dev/null | grep -q ':22 ' && echo yes || echo no")) === 'yes';
            if (!$listening) {
                logMessage('[ERROR] pmssHealOpensshServerIfMissing: port 22 not listening after restart');
                throw new RuntimeException('pmssHealOpensshServerIfMissing: sshd not listening after restart');
            }
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
     * Ensure openssh-server is at a version that's compatible with the held
     * libssl3 (3.0.17). When libssl3 is held at 3.0.17 (per
     * pmssHoldLibssl3ForPeclSsh2Compat), openssh-server MUST be at deb12u7 or
     * older — anything newer (deb12u8, deb12u9, deb12u10, ...) requires libssl3
     * >= 3.0.18 or 3.0.19 and lives in an unconfigured/broken-dep state that
     * fails `dpkg --configure -a` every PMSS update.
     *
     * Cascade-victim hosts that were emergency-recovered with `dpkg -i
     * openssh-server_deb12u9_amd64.deb` from the live apt cache end up in this
     * broken state — the binary runs (sshd is alive) but dpkg metadata says
     * "unconfigured" and any subsequent apt-resolver decision could re-trigger
     * the 2026-04-30 cascade-removal pattern (`apt --fix-broken install` would
     * try to either remove openssh-server or unhold libssl3 to satisfy the
     * dep).
     *
     * Canonical baseline (verified on sea-sparrow 2026-05-21): never-cascaded
     * Debian 12 hosts have openssh-server at deb12u7 in `ii` state, NOT held;
     * libssl3+openssl held at 3.0.17 alone is sufficient because apt sees
     * deb12u9 candidate cannot satisfy held libssl3 and leaves the package at
     * deb12u7.
     *
     * This function brings cascade-victim hosts to the same canonical state.
     *
     * Idempotent: no-op on hosts already at deb12u7 or older.
     *
     * Refs #436. Origin: SUPER JOUKO 2026-05-21 — manual openssh-* holds on
     * cascade-recovered hosts were the symptom; the disease is unconfigured
     * deb12u9. Operator directive: "PMSS CONFIG CHANGES ARE ONLY THROUGH PMSS
     * UPDATE NEVER MANUAL."
     */
    function pmssEnsureOpensshCompatibleWithHeldLibssl3(?int $distroVersion = null): void
    {
        $targetOpenssh = '1:9.2p1-2+deb12u7';

        if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
            logMessage('[DRY-RUN] pmssEnsureOpensshCompatibleWithHeldLibssl3: skipping all mutations');
            return;
        }

        $effectiveVersion = pmssDistroVersionFromEnv($distroVersion);
        if ($effectiveVersion !== 12) {
            logMessage('[SKIP] pmssEnsureOpensshCompatibleWithHeldLibssl3: not Debian 12 (detected: '.$effectiveVersion.')');
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

        $compareVersions = static function (string $left, string $operator, string $right): bool {
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
        };

        if (!$compareVersions($opensshVer, 'gt', $targetOpenssh)) {
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
            pmssAptDpkgEnvPrefix().' apt-mark unhold openssh-server openssh-client openssh-sftp-server 2>/dev/null || true');

        // Download the target version trio.
        $downloadRc = runStep(
            'Downloading openssh-server/client/sftp at '.$targetOpenssh.' for libssl3-3.0.17-compat downgrade',
            'cd '.escapeshellarg($tmpDir).' && '
            .pmssAptDpkgEnvPrefix().' apt-get download '
            .'openssh-server='.escapeshellarg($targetOpenssh).' '
            .'openssh-client='.escapeshellarg($targetOpenssh).' '
            .'openssh-sftp-server='.escapeshellarg($targetOpenssh).' 2>&1'
        );
        if ($downloadRc !== 0) {
            pmssRemovePrivateTempDir($tmpDir, 'pmss-openssh-downgrade-', 'Cleaning openssh-downgrade download cache');
            logMessage('[ERROR] pmssEnsureOpensshCompatibleWithHeldLibssl3: apt-get download failed — target version may have aged out of the repo');
            throw new RuntimeException('pmssEnsureOpensshCompatibleWithHeldLibssl3: download failed');
        }

        $debs = glob($tmpDir.'/*.deb') ?: [];
        sort($debs);
        if (count($debs) < 3) {
            pmssRemovePrivateTempDir($tmpDir, 'pmss-openssh-downgrade-', 'Cleaning openssh-downgrade download cache');
            logMessage('[ERROR] pmssEnsureOpensshCompatibleWithHeldLibssl3: incomplete deb set (got '.count($debs).')');
            throw new RuntimeException('pmssEnsureOpensshCompatibleWithHeldLibssl3: incomplete debs');
        }

        // Install via dpkg-direct with conf-preserve (same conf-handling posture
        // as pmssHealOpensshServerIfMissing — never replace site sshd_config).
        $installRc = runStep(
            'Installing openssh-server/client/sftp '.$targetOpenssh.' via dpkg-direct (conf-preserve, libssl3-3.0.17-compat downgrade)',
            dpkgCmd('--force-confdef --force-confold -i '.implode(' ', array_map('escapeshellarg', $debs)))
        );
        pmssRemovePrivateTempDir($tmpDir, 'pmss-openssh-downgrade-', 'Cleaning openssh-downgrade download cache');
        if ($installRc !== 0) {
            logMessage('[ERROR] pmssEnsureOpensshCompatibleWithHeldLibssl3: dpkg -i failed during downgrade');
            throw new RuntimeException('pmssEnsureOpensshCompatibleWithHeldLibssl3: dpkg -i failed');
        }

        // After downgrade, sshd_config may have been touched by dpkg's
        // conf-handling decision tree. Re-deploy the PMSS template explicitly
        // (mirror pmssHealOpensshServerIfMissing belt-and-suspenders posture).
        $tmpl = '/etc/seedbox/config/template.sshd_config';
        $live = '/etc/ssh/sshd_config';
        if (file_exists($tmpl)) {
            runStep('Backing up sshd_config before re-deploying PMSS template (post-downgrade)',
                'cp '.escapeshellarg($live).' '.escapeshellarg($live.'.pre-downgrade-'.date('Ymd-His')));
            runStep('Re-deploying PMSS sshd_config template (post-downgrade)',
                'cp '.escapeshellarg($tmpl).' '.escapeshellarg($live));
            runStep('Setting sshd_config permissions after template re-deploy',
                'chmod 644 '.escapeshellarg($live));
        }

        // Verify sshd config parses before restart.
        $sshdT = 1;
        @exec('/usr/sbin/sshd -t 2>&1', $sshdTOut, $sshdT);
        if ($sshdT !== 0) {
            logMessage('[ERROR] pmssEnsureOpensshCompatibleWithHeldLibssl3: sshd -t failed after downgrade: '.implode(' | ', array_slice($sshdTOut, 0, 5)));
            throw new RuntimeException('pmssEnsureOpensshCompatibleWithHeldLibssl3: sshd -t failed');
        }

        // Restart ssh.service so the downgraded sshd takes over.
        runStep('Restarting ssh.service to load downgraded openssh-server',
            'systemctl daemon-reload && systemctl restart ssh');
        sleep(2);
        $listening = trim((string) @shell_exec("ss -tln 2>/dev/null | grep -q ':22 ' && echo yes || echo no")) === 'yes';
        if (!$listening) {
            logMessage('[ERROR] pmssEnsureOpensshCompatibleWithHeldLibssl3: port 22 not listening after downgrade-restart');
            throw new RuntimeException('pmssEnsureOpensshCompatibleWithHeldLibssl3: sshd not listening after restart');
        }

        $newVer = trim((string) @shell_exec('dpkg-query -W -f=\'${Version}\' openssh-server 2>/dev/null'));
        logMessage('[OK] pmssEnsureOpensshCompatibleWithHeldLibssl3: openssh-server downgraded from '.$opensshVer.' to '.$newVer.' (libssl3-3.0.17-compatible canonical state)');
    }
