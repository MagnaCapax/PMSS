<?php
/**
 * Environment bootstrap helpers for update-step2.
 *
 * Package-phase invariant: update-step2 must run the non-interactive apt setup,
 * complete pending dpkg work, then apply the baseline selections before other
 * modules execute. Keep this ordering intact: dpkg baselines are the sole
 * package source in the default update flow.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/packageState.php';
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
        $validatedBaselines = array_filter(array_keys($baselines), static function (int $major): bool {
            return $major <= 12;
        });
        $latestBaseline = $baselines ? max(array_keys($baselines)) : null;
        $latestValidatedBaseline = $validatedBaselines ? max($validatedBaselines) : null;
        $fallbackBaseline = $latestValidatedBaseline ?? $latestBaseline;
        $fallbackPath = $fallbackBaseline !== null ? $baselines[$fallbackBaseline] : null;

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
        foreach (array_filter([$fallbackPath, $baseDir.'/selections.txt']) as $fallbackCandidate) {
            if (!in_array($fallbackCandidate, $candidates, true)) {
                $candidates[] = $fallbackCandidate;
            }
        }

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
     * Persist the sanitized dpkg baseline to a temporary file.
     *
     * Callers must treat a null return as a hard stop. Falling back to the raw
     * baseline would bypass the validation pass that produced the sanitized
     * payload.
     *
     * @param array<int, string> $sanitised
     */
    function pmssWriteSanitisedDpkgSelectionsTempFile(array $sanitised): ?string
    {
        $tmpSelection = @tempnam(sys_get_temp_dir(), 'pmss-selections-');
        if ($tmpSelection === false) {
            logMessage('[ERROR] Unable to create temporary file for sanitized dpkg selections baseline');
            return null;
        }

        $payload = implode(PHP_EOL, $sanitised).PHP_EOL;
        if (@file_put_contents($tmpSelection, $payload, LOCK_EX) === false) {
            @unlink($tmpSelection);
            logMessage('[ERROR] Unable to write temporary file for sanitized dpkg selections baseline');
            return null;
        }

        return $tmpSelection;
}

    /**
     * Validate temp-dir prefixes before they reach tempnam() or cleanup guards.
     */
    function pmssPrivateTempPrefixIsSafe(string $prefix): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $prefix) === 1;
    }

    /**
     * Create a private temporary directory under the process temp root.
     */
    function pmssCreatePrivateTempDir(string $prefix): ?string
    {
        if (!pmssPrivateTempPrefixIsSafe($prefix)) {
            logMessage('[WARN] Refusing private temporary directory with unsafe prefix');
            return null;
        }

        $path = @tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            return null;
        }

        if (!@unlink($path)) {
            return null;
        }
        if (!@mkdir($path, 0700)) {
            return null;
        }

        return $path;
}

/**
     * Resolve a PMSS-owned temporary directory before destructive cleanup.
     */
    function pmssPrivateTempDirRealpath(string $path, string $prefix, ?callable $logger = null): ?string
    {
        $log = $logger ?: 'logMessage';
        $base = realpath(sys_get_temp_dir());
        $real = $path !== '' && !is_link($path) ? realpath($path) : false;

        if (!pmssPrivateTempPrefixIsSafe($prefix)) {
            $log('[WARN] Refusing temporary directory cleanup for unsafe prefix');
            return null;
        }

        if ($base === false || $base === DIRECTORY_SEPARATOR || $real === false || !is_dir($real)) {
            $log('[WARN] Refusing temporary directory cleanup for unresolved path: '.$path);
            return null;
        }

        $basePrefix = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (strpos($real, $basePrefix) !== 0 || strpos(basename($real), $prefix) !== 0) {
            $log('[WARN] Refusing temporary directory cleanup outside PMSS temp scope: '.$real);
            return null;
        }

        return $real;
}

/**
     * Remove a PMSS-owned temporary directory after verifying its scope.
     */
    function pmssRemovePrivateTempDir(string $path, string $prefix, string $description): int
    {
        $real = pmssPrivateTempDirRealpath($path, $prefix);
        if ($real === null) {
            return 1;
        }

        return runStep($description, 'rm -rf '.escapeshellarg($real));
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
                $tmpSelection = pmssWriteSanitisedDpkgSelectionsTempFile($sanitised);
                if ($tmpSelection === null) {
                    logMessage('[ERROR] Refusing to apply raw dpkg selections baseline after sanitized baseline staging failed');
                    return false;
                }
                $selectionPath = $tmpSelection;
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
     * Move the legacy localnet file into the configuration directory.
     */
    function pmssMigrateLegacyLocalnet(): void
    {
        if (file_exists('/etc/seedbox/localnet') && !file_exists('/etc/seedbox/config/localnet')) {
            runStep('Migrating legacy localnet configuration', 'mv /etc/seedbox/localnet /etc/seedbox/config/localnet');
        }
}
