<?php
/**
 * Environment bootstrap helpers for update-step2.
 *
 * Package-phase invariant: update-step2 must run the non-interactive apt setup,
 * complete pending dpkg work, apply the baseline selections, then flush any
 * queued installs before other modules execute. Keep this ordering intact—the
 * codebase is converging on the dpkg baseline as the sole package source.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/apps/packages/helpers.php';
require_once __DIR__.'/managedPath.php';

/**
     * Remove lingering MediaArea apt sources and cached bootstrap packages.
     *
     * Older repo-mediaarea packages now use control.tar.zst and are not needed
     * since we ship our own apt templates. Best-effort cleanup keeps dpkg
     * baseline apply from tripping over stale .list files or cached .debs.
     */
    function pmssPruneLegacyMediaArea(): void
    {
        runStep('Removing legacy MediaArea apt list files', 'rm -f /etc/apt/sources.list.d/mediaarea*.list');
        runStep('Removing legacy MediaArea apt preferences/keys', 'rm -f /etc/apt/preferences.d/mediaarea* /etc/apt/trusted.gpg.d/mediaarea*.gpg');

        $sources = '/etc/apt/sources.list';
        if (pmssManagedPathIsSafe($sources, 'apt sources.list', 'logMessage') && is_readable($sources)) {
            $data = @file_get_contents($sources);
            if ($data !== false && preg_match('/^[ \t]*[^#\r\n].*mediaarea/im', $data) === 1) {
                $backup = $sources.'.pmss-backup-'.date('YmdHis');
                if (!pmssManagedPathIsSafe($backup, 'apt sources.list backup', 'logMessage')) {
                    return;
                }
                if (!@copy($sources, $backup)) {
                    logMessage('[WARN] Unable to back up apt sources.list before pruning MediaArea entries: '.$backup);
                    return;
                }
                $mutated = preg_replace('/^([ \t]*)([^#\r\n].*mediaarea.*)$/im', '$1# PMSS-disabled mediaarea: $2', $data);
                if ($mutated !== null && $mutated !== $data && !pmssWriteManagedPathFile($sources, $mutated, 'apt sources.list', 'logMessage')) {
                    return;
                }
            }
        }

        runStep('Purging legacy MediaArea package', aptCmd('purge -y repo-mediaarea || true'));
        runStep('Holding legacy MediaArea package to block reinstalls', 'apt-mark hold repo-mediaarea || true');
        runStep(
            'Removing cached MediaArea packages',
            'rm -f /var/cache/apt/archives/repo-mediaarea_*.deb '
            .'/var/cache/apt/archives/partial/repo-mediaarea_*.deb '
            .'/var/lib/apt/lists/*mediaarea* /var/lib/apt/lists/partial/*mediaarea* '
            .'/etc/apt/sources.list.d/*.list.pmss-backup-*'
        );
}

/**
     * Ensure apt operates in fully non-interactive mode.
     */
    function pmssConfigureAptNonInteractive(?callable $logger = null): void
    {
        $log = $logger ?: 'logMessage';
        $path = '/etc/apt/apt.conf.d/90pmss-noninteractive';
        $contents = <<<CONF
Dpkg::Options {
    "--force-confdef";
    "--force-confold";
}
APT::Get::Assume-Yes "true";
APT::Color "0";
DPkg::Use-Pty "0";
CONF;

        $existing = @file_get_contents($path);
        if ($existing === false || trim($existing) !== trim($contents)) {
            if (!pmssWriteManagedPathFile($path, $contents, 'apt non-interactive configuration', $log)) {
                return;
            }
            @chmod($path, 0644);
            $log('Updated apt non-interactive configuration ('.$path.')');
            return;
        }

        $log('[SKIP] apt non-interactive configuration already up to date');
}

/**
     * Finish any interrupted dpkg configuration runs.
     */
    function pmssCompletePendingDpkg(): void
    {
        // #TODO replace special-casing with a generic unit-unmask helper when more services require it.
        $hasSystemd = is_dir('/run/systemd/system');
        if ($hasSystemd) {
            $state = trim((string) @shell_exec('systemctl is-enabled proftpd.service 2>/dev/null'));
            if ($state === 'masked') {
                runCommand('systemctl unmask proftpd.service');
            }
        }

        $rc = runStep('Completing pending dpkg configuration', 'dpkg --configure -a');
        if ($rc !== 0 && $hasSystemd) {
            runStep('Unmasking proftpd for dpkg retry', 'systemctl unmask proftpd.service || true');
        }
        if ($rc !== 0) {
            runStep('Retrying proftpd configure', 'dpkg --configure proftpd-core proftpd-mod-crypto proftpd-mod-wrap proftpd-basic || true');
        }
}

/**
     * Return the newest validated dpkg baseline major for automatic selection.
     *
     * Debian 13 stays experimental until its baseline is captured from a
     * converged host and replay-validated. Keep automatic fallback on the latest
     * validated baseline so provisional future manifests do not become active
     * package authority just because the file exists.
     *
     * @param array<int, string> $baselines
     */
    function pmssLatestValidatedDpkgBaselineMajor(array $baselines): ?int
    {
        $validated = [];
        foreach (array_keys($baselines) as $major) {
            if ((int) $major <= 12) {
                $validated[] = (int) $major;
            }
        }

        return $validated ? max($validated) : null;
}

/**
     * Resolve the best dpkg selections baseline file for the detected distro version.
     *
     * This is pure selection logic (no command execution) so tests can validate
     * baseline availability without mutating a host.
     */
    function pmssSelectDpkgSelectionsBaseline(?int $distroVersion = null, ?callable $logger = null): ?string
    {
        $log = $logger ?: 'logMessage';
        $baseDir = __DIR__.'/dpkg';
        $baselines = [];
        foreach (glob($baseDir.'/selections-debian*.txt') ?: [] as $path) {
            if (preg_match('/selections-debian([0-9]+)\\.txt$/', $path, $match)) {
                $baselines[(int) $match[1]] = $path;
            }
        }
        $latestBaseline = $baselines ? max(array_keys($baselines)) : null;
        $latestValidatedBaseline = pmssLatestValidatedDpkgBaselineMajor($baselines);

        $candidates = [];
        $requestedPath = null;
        $requestedBaselineValidated = true;
        if ($distroVersion !== null) {
            $requestedPath = $baselines[$distroVersion] ?? sprintf('%s/selections-debian%d.txt', $baseDir, $distroVersion);
            $requestedBaselineValidated = $distroVersion <= 12;
            if ($requestedBaselineValidated) {
                $candidates[] = $requestedPath;
            }
        }
        if ($latestValidatedBaseline !== null) {
            $latestPath = $baselines[$latestValidatedBaseline];
            if (!in_array($latestPath, $candidates, true)) {
                $candidates[] = $latestPath;
            }
        } elseif ($latestBaseline !== null) {
            $latestPath = $baselines[$latestBaseline];
            if (!in_array($latestPath, $candidates, true)) {
                $candidates[] = $latestPath;
            }
        }
        $candidates[] = $baseDir.'/selections.txt';

        $selected = null;
        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                $selected = $candidate;
                break;
            }
        }

        if ($selected === null) {
            return null;
        }

        if ($distroVersion !== null && $requestedPath !== null && $selected !== $requestedPath) {
            if (!$requestedBaselineValidated && is_readable($requestedPath)) {
                if ($latestValidatedBaseline !== null && isset($baselines[$latestValidatedBaseline]) && $selected === $baselines[$latestValidatedBaseline]) {
                    $log(sprintf('[WARN] Debian %d dpkg baseline exists but is not validated for automatic use; using Debian %d baseline (%s).', $distroVersion, $latestValidatedBaseline, basename($selected)));
                } else {
                    $log(sprintf('[WARN] Debian %d dpkg baseline exists but is not validated for automatic use; using %s.', $distroVersion, basename($selected)));
                }
            } elseif ($latestValidatedBaseline !== null && $latestValidatedBaseline < $distroVersion && isset($baselines[$latestValidatedBaseline]) && $selected === $baselines[$latestValidatedBaseline]) {
                $log(sprintf('[WARN] Debian %d dpkg baseline missing; using Debian %d baseline (%s).', $distroVersion, $latestValidatedBaseline, basename($selected)));
            } else {
                $log(sprintf('[WARN] Debian %d dpkg baseline unavailable; using %s.', $distroVersion, basename($selected)));
            }
        }

        return $selected;
}

/**
     * Apply the baseline dpkg selection snapshot so required packages stay present.
     *
     * @return bool True when the baseline was parsed and applied successfully.
     */
    function pmssApplyDpkgSelections(?int $distroVersion = null, bool $skipUpdate = false): bool
    {
        $selections = pmssSelectDpkgSelectionsBaseline($distroVersion);
        if ($selections === null) {
            return true;
        }

        if (!$skipUpdate) {
            pmssPruneLegacyMediaArea();
            runStep('Refreshing apt cache before dpkg selection', aptCmd('update'));
        }
        runStep('Refreshing dpkg availability database', 'apt-cache dumpavail | dpkg --merge-avail');

        $selectionPath = $selections;
        $tmpSelection  = null;
        $lines         = @file($selections, FILE_IGNORE_NEW_LINES);
        $success       = true;
        $warnings      = false;
        if ($lines !== false) {
            $sanitised = [];
            $shortFormSeen = false;
            $t0 = microtime(true);
            $droppedUnavailable = [];
            $droppedObsolete    = [];
            $droppedKernel      = [];
            $runtimeVersion     = pmssDistroVersionFromEnv($distroVersion);
            foreach ($lines as $idx => $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', $trimmed);
                if (count($parts) === 1) {
                    // Debian 12 baseline currently uses short-form lines containing only
                    // the package name. Treat these as "install" selections instead of
                    // emitting per-line warnings. Aggregated validation below still
                    // enforces allowed package/state patterns.
                    $package = $parts[0];
                    $state   = 'install';
                    $shortFormSeen = true;
                } elseif (count($parts) >= 2) {
                    $package = $parts[0];
                    $state   = $parts[1];
                } else {
                    pmssLogStatus('WARN', sprintf('Ignoring malformed dpkg selection line %d: %s', $idx + 1, $trimmed), 0);
                    $warnings = true;
                    continue;
                }
                // Skip problematic or deprecated packages from baseline
                $lower = strtolower($package);

                // On Debian 12+ WireGuard is part of the stock kernel; the
                // out-of-tree DKMS module is no longer required and can cause
                // BUILD_EXCLUSIVE failures during kernel upgrades. Force its
                // selection state to "deinstall" so dselect-upgrade removes it.
                if ($runtimeVersion >= 12 && $lower === 'wireguard-dkms') {
                    $sanitised[] = $package."\tdeinstall";
                    $warnings = true;
                    $droppedObsolete[] = $package;
                    continue;
                }

                // Legacy MediaArea bootstrap package: repo-mediaarea is no longer
                // required now that apt sources are templated directly. Newer
                // builds also use control.tar.zst which older dpkg cannot unpack.
                // Always mark it for deinstallation so hosts converge away from it.
                if ($lower === 'repo-mediaarea') {
                    $sanitised[] = $package."\tdeinstall";
                    $warnings = true;
                    $droppedObsolete[] = $package;
                    continue;
                }

                // Drop legacy names we no longer install via apt (tarball/venv used instead)
                if (in_array($lower, ['nzbdrone', 'pyload-cli'], true)) { $warnings = true; $droppedObsolete[] = $package; continue; }
                // Drop version-pinned kernel images silently; rely on meta 'linux-image-amd64'
                if (preg_match('/^linux-image-[0-9]/i', $package)) { $warnings = true; $droppedKernel[] = $package; continue; }
                // Drop versioned PHP and Python3 packages from baseline; rely on meta/unversioned names
                if (preg_match('/^php[0-9]+\.[0-9]+\-/i', $package)) { $warnings = true; $droppedObsolete[] = $package; continue; }
                if (preg_match('/^python3\.[0-9]+\-/i', $package)) { $warnings = true; $droppedObsolete[] = $package; continue; }
                if (!preg_match('/^[a-z0-9.+:-]+$/i', $package) || !preg_match('/^(install|hold|purge|deinstall)$/i', $state)) {
                    pmssLogStatus('WARN', sprintf('Invalid dpkg selection entry at line %d: %s', $idx + 1, $trimmed), 0);
                    $warnings = true;
                    continue;
                }
                // If requesting install of a package not available in the current apt cache, drop it
                if (strtolower($state) === 'install' && !pmssPackageAvailable($package)) { $warnings = true; if (strtolower($package) !== 'cgroup-bin') { $droppedUnavailable[] = $package; } continue; }
                $sanitised[] = $package."\t".strtolower($state);
            }

            if (!empty($sanitised)) {
                $tmpSelection = tempnam(sys_get_temp_dir(), 'pmss-selections-');
                if ($tmpSelection !== false && file_put_contents($tmpSelection, implode(PHP_EOL, $sanitised).PHP_EOL) !== false) {
                    $selectionPath = $tmpSelection;
                } elseif ($tmpSelection !== false) {
                    @unlink($tmpSelection);
                    $tmpSelection = null;
                    $warnings     = true;
                }
            }

            // Aggregate summary logs instead of per-package noise
            if (!empty($droppedUnavailable)) {
                $sample = array_slice($droppedUnavailable, 0, 10);
                pmssLogStatus('SKIP', sprintf('Baseline: dropped %d unavailable packages (first %d: %s)', count($droppedUnavailable), count($sample), implode(', ', $sample)), 0, microtime(true)-$t0);
            }
            if (!empty($droppedKernel)) {
                pmssLogStatus('SKIP', sprintf('Baseline: dropped %d versioned kernel packages', count($droppedKernel)), 0, 0.0);
            }
            if (!empty($droppedObsolete)) {
                pmssLogStatus('SKIP', sprintf('Baseline: dropped %d obsolete entries (legacy names)', count($droppedObsolete)), 0, 0.0);
            }
            if ($shortFormSeen) {
                pmssLogStatus('INFO', 'Baseline: detected short-form dpkg selection entries; treating as \"install\" state', 0, 0.0);
            }
        }

        $cmd = sprintf('dpkg --set-selections < %s', escapeshellarg($selectionPath));
        $success = $success && (runStep('Applying dpkg selection baseline', $cmd) === 0);

        $installCmd = aptCmd('dselect-upgrade -y');
        $rc = runStep('Installing packages from selection baseline', $installCmd);
        if ($rc !== 0) {
            runStep('Attempting apt fix-broken install (dpkg baseline)', aptCmd('--fix-broken install -y'));
            $retryRc = runStep('Retrying package selection install', $installCmd);
            if ($retryRc !== 0) {
                logmsg('[ERROR] Package baseline installation still failing after retry');
            }
            $success = $success && ($retryRc === 0);
        }

        if ($tmpSelection !== null) {
            @unlink($tmpSelection);
        }

        if ($warnings) {
            pmssLogStatus('WARN', 'Dpkg selection baseline contained ignored entries; proceeding with remaining packages', 0);
        }

        return $success;
}

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
     * - Never downgrade with apt without simulate-first cascade detection.
     * - If simulate shows openssh removals, use dpkg-direct path only:
     *   apt-mark unhold -> apt-get download -> dpkg -i -> apt-mark hold.
     * - If dpkg-direct cannot proceed, abort update-step2 with non-zero.
     *
     * Idempotent behavior:
     * - If already at 3.0.17-1~deb12u2 and both packages are held, no-op.
     *
     * Refs #436.
     */
    function pmssHoldLibssl3ForPeclSsh2Compat(?int $distroVersion = null): void
    {
        $targetVersion = '3.0.17-1~deb12u2';

        if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
            logMessage('[DRY-RUN] pmssHoldLibssl3ForPeclSsh2Compat: skipping all mutations');
            return;
        }

        $effectiveVersion = pmssDistroVersionFromEnv($distroVersion);
        if ($effectiveVersion !== 12) {
            logMessage('[SKIP] pmssHoldLibssl3ForPeclSsh2Compat: not Debian 12 (detected: '.$effectiveVersion.')');
            return;
        }

        $rawVer = trim((string) @shell_exec("dpkg-query -W -f='${Version}' libssl3 2>/dev/null"));
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
            // Guard downgrade safety: if apt predicts openssh removals, switch to dpkg-direct path.
            $simulateCmd = 'apt-get install --simulate --allow-downgrades '
                .'libssl3='.$targetVersion.' openssl='.$targetVersion.' 2>&1';
            $simulateOut = (string) @shell_exec($simulateCmd);
            $opensshCascade = preg_match('/Remv:\s+.*openssh|Remv\s+openssh/i', $simulateOut) === 1;

            if ($opensshCascade) {
                logMessage('[WARN] pmssHoldLibssl3ForPeclSsh2Compat: apt simulate shows openssh removals; using dpkg-direct downgrade path');
                runStep('Unholding libssl3/openssl for dpkg-direct downgrade', 'apt-mark unhold libssl3 openssl 2>/dev/null || true');

                $tmpDir = sys_get_temp_dir().'/pmss-libssl-'.substr(md5(uniqid('', true)), 0, 8);
                if (!@mkdir($tmpDir, 0700, true)) {
                    logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: cannot create temporary directory for dpkg-direct downgrade');
                    throw new RuntimeException('Unable to create temporary directory for dpkg-direct downgrade');
                }

                $downloadRc = runStep(
                    'Downloading libssl3/openssl '.$targetVersion.' deb packages',
                    'cd '.escapeshellarg($tmpDir)
                    .' && apt-get download libssl3='.$targetVersion.' openssl='.$targetVersion.' 2>&1'
                );
                if ($downloadRc !== 0) {
                    @runStep('Cleaning dpkg-direct download cache', 'rm -rf '.escapeshellarg($tmpDir));
                    logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: apt-get download failed; refusing unsafe fallback');
                    throw new RuntimeException('dpkg-direct downgrade blocked: download failed for libssl3/openssl '.$targetVersion);
                }

                $debs = glob($tmpDir.'/*.deb') ?: [];
                sort($debs);
                if (count($debs) < 2) {
                    @runStep('Cleaning dpkg-direct download cache', 'rm -rf '.escapeshellarg($tmpDir));
                    logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: missing downloaded .deb files; refusing unsafe fallback');
                    throw new RuntimeException('dpkg-direct downgrade blocked: expected .deb files were not downloaded');
                }

                $installRc = runStep(
                    'Installing libssl3/openssl via dpkg-direct (openssh-safe path)',
                    'dpkg -i '.implode(' ', array_map('escapeshellarg', $debs))
                );
                runStep('Cleaning dpkg-direct download cache', 'rm -rf '.escapeshellarg($tmpDir));
                if ($installRc !== 0) {
                    logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: dpkg-direct install failed; refusing unsafe fallback');
                    throw new RuntimeException('dpkg-direct downgrade blocked: dpkg -i failed');
                }
            } else {
                $downgradeCmd = aptCmd('install -y --allow-downgrades libssl3='.$targetVersion.' openssl='.$targetVersion);
                $downgradeRc  = runStep('Downgrading libssl3/openssl to '.$targetVersion.' (simulate-verified safe)', $downgradeCmd);
                if ($downgradeRc !== 0) {
                    logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: guarded apt downgrade to '.$targetVersion.' failed');
                    throw new RuntimeException('Unable to downgrade libssl3/openssl to '.$targetVersion);
                }
            }

            $downgraded = true;
        }

        $postVer = trim((string) @shell_exec("dpkg-query -W -f='${Version}' libssl3 2>/dev/null"));
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
     * Move the legacy localnet file into the configuration directory.
     */
    function pmssMigrateLegacyLocalnet(): void
    {
        if (file_exists('/etc/seedbox/localnet') && !file_exists('/etc/seedbox/config/localnet')) {
            runStep('Migrating legacy localnet configuration', 'mv /etc/seedbox/localnet /etc/seedbox/config/localnet');
        }
}
