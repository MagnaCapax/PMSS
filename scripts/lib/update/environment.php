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
        if (is_readable($sources)) {
            $data = @file_get_contents($sources);
            if ($data !== false && preg_match('/^[ \t]*[^#\r\n].*mediaarea/im', $data) === 1) {
                $backup = $sources.'.pmss-backup-'.date('YmdHis');
                @copy($sources, $backup);
                $mutated = preg_replace('/^([ \t]*)([^#\r\n].*mediaarea.*)$/im', '$1# PMSS-disabled mediaarea: $2', $data);
                if ($mutated !== null && $mutated !== $data) {
                    @file_put_contents($sources, $mutated);
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
            if (@file_put_contents($path, $contents) === false) {
                $log('[WARN] Unable to write apt non-interactive configuration at '.$path);
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
        $latestBaseline = $baselines ? max(array_keys($baselines)) : null;

        $candidates = [];
        $requestedPath = null;
        if ($distroVersion !== null) {
            $requestedPath = $baselines[$distroVersion] ?? sprintf('%s/selections-debian%d.txt', $baseDir, $distroVersion);
            $candidates[] = $requestedPath;
        }
        // Default to the newest baseline we have when the target release is newer than expected.
        // #TODO #Debian13: capture and ship selections-debian13.txt (platform sign-off required).
        if ($latestBaseline !== null) {
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
            if ($latestBaseline !== null && $latestBaseline < $distroVersion && $selected === $baselines[$latestBaseline]) {
                $log(sprintf('[WARN] Debian %d dpkg baseline missing; using Debian %d baseline (%s).', $distroVersion, $latestBaseline, basename($selected)));
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
            $runtimeVersion     = $distroVersion !== null ? (int) $distroVersion : (int) (getenv('PMSS_DISTRO_VERSION') ?: 0);
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
     * Move the legacy localnet file into the configuration directory.
     */
    function pmssMigrateLegacyLocalnet(): void
    {
        if (file_exists('/etc/seedbox/localnet') && !file_exists('/etc/seedbox/config/localnet')) {
            runStep('Migrating legacy localnet configuration', 'mv /etc/seedbox/localnet /etc/seedbox/config/localnet');
        }
}
