<?php
/**
 * Environment bootstrap helpers for update-step2.
 *
 * Package-phase invariant: update-step2 must run the non-interactive apt setup,
 * complete pending dpkg work, apply the baseline selections, then flush any
 * queued installs before other modules execute. Keep this ordering intact—the
 * codebase is converging on the dpkg baseline as the sole package source.
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/apps/packages/helpers.php';

if (!function_exists('pmssConfigureAptNonInteractive')) {
    /**
     * Ensure apt operates in fully non-interactive mode.
     */
    function pmssConfigureAptNonInteractive(?callable $logger = null): void
    {
        $log = pmssSelectLogger($logger);
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
}

if (!function_exists('pmssCompletePendingDpkg')) {
    /**
     * Finish any interrupted dpkg configuration runs.
     */
    function pmssCompletePendingDpkg(): void
    {
        // #TODO replace special-casing with a generic unit-unmask helper when more services require it.
        if (is_dir('/run/systemd/system')) {
            $state = trim((string) @shell_exec('systemctl is-enabled proftpd.service 2>/dev/null'));
            if ($state === 'masked') {
                runCommand('systemctl unmask proftpd.service');
            }
        }

        $rc = runStep('Completing pending dpkg configuration', 'dpkg --configure -a');
        if ($rc !== 0) {
            if (is_dir('/run/systemd/system')) {
                runStep('Unmasking proftpd for dpkg retry', 'systemctl unmask proftpd.service || true');
            }
            runStep('Retrying proftpd configure', 'dpkg --configure proftpd-core proftpd-mod-crypto proftpd-mod-wrap proftpd-basic || true');
        }
    }
}

if (!function_exists('pmssApplyDpkgSelections')) {
    /**
     * Apply the baseline dpkg selection snapshot so required packages stay present.
     *
     * @return bool True when the baseline was parsed and applied successfully.
     */
    function pmssApplyDpkgSelections(?int $distroVersion = null, bool $skipUpdate = false): bool
    {
        $baseDir = __DIR__.'/dpkg';
        $candidates = [];
        if ($distroVersion !== null) {
            $candidates[] = sprintf('%s/selections-debian%d.txt', $baseDir, $distroVersion);
        }
        $candidates[] = $baseDir.'/selections-debian11.txt';
        $candidates[] = $baseDir.'/selections.txt';

        $selections = null;
        foreach ($candidates as $candidate) {
            if ($candidate !== null && is_readable($candidate)) {
                $selections = $candidate;
                break;
            }
        }
        if ($selections === null) {
            return true;
        }

        if (!$skipUpdate) {
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
                    if (function_exists('pmssLogStatus')) {
                        pmssLogStatus('WARN', sprintf('Ignoring malformed dpkg selection line %d: %s', $idx + 1, $trimmed), 0);
                    } elseif (function_exists('logmsg')) {
                        logmsg(sprintf('[WARN] Ignoring malformed dpkg selection line %d: %s', $idx + 1, $trimmed));
                    }
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
                    if (function_exists('pmssLogStatus')) { pmssLogStatus('WARN', sprintf('Invalid dpkg selection entry at line %d: %s', $idx + 1, $trimmed), 0); }
                    elseif (function_exists('logmsg')) { logmsg(sprintf('[WARN] Invalid dpkg selection entry at line %d: %s', $idx + 1, $trimmed)); }
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
            if (!empty($droppedUnavailable) && function_exists('pmssLogStatus')) {
                $sample = array_slice($droppedUnavailable, 0, 10);
                pmssLogStatus('SKIP', sprintf('Baseline: dropped %d unavailable packages (first %d: %s)', count($droppedUnavailable), count($sample), implode(', ', $sample)), 0, microtime(true)-$t0);
            }
            if (!empty($droppedKernel) && function_exists('pmssLogStatus')) {
                pmssLogStatus('SKIP', sprintf('Baseline: dropped %d versioned kernel packages', count($droppedKernel)), 0, 0.0);
            }
            if (!empty($droppedObsolete) && function_exists('pmssLogStatus')) {
                pmssLogStatus('SKIP', sprintf('Baseline: dropped %d obsolete entries (legacy names)', count($droppedObsolete)), 0, 0.0);
            }
            if ($shortFormSeen && function_exists('pmssLogStatus')) {
                pmssLogStatus('INFO', 'Baseline: detected short-form dpkg selection entries; treating as \"install\" state', 0, 0.0);
            }
        }

        $cmd = sprintf('dpkg --set-selections < %s', escapeshellarg($selectionPath));
        $rc = runStep('Applying dpkg selection baseline', $cmd);
        if ($rc !== 0) {
            $success = false;
        }

        $installCmd = aptCmd('dselect-upgrade -y');
        $rc = runStep('Installing packages from selection baseline', $installCmd);
        if ($rc !== 0) {
            runStep('Attempting apt fix-broken install (dpkg baseline)', aptCmd('--fix-broken install -y'));
            $retryRc = runStep('Retrying package selection install', $installCmd);
            if ($retryRc !== 0 && function_exists('logmsg')) {
                logmsg('[ERROR] Package baseline installation still failing after retry');
            }
            $success = $success && ($retryRc === 0);
        }

        if ($tmpSelection !== null) {
            @unlink($tmpSelection);
        }

        if ($warnings) {
            if (function_exists('pmssLogStatus')) { pmssLogStatus('WARN', 'Dpkg selection baseline contained ignored entries; proceeding with remaining packages', 0); }
            elseif (function_exists('logmsg')) { logmsg('[WARN] Dpkg selection baseline contained ignored entries; proceeding with remaining packages'); }
        }

        return $success;
    }
}

if (!function_exists('pmssCleanupMediaareaBootstrapPackage')) {
    /**
     * Remove the legacy MediaArea bootstrap package when present.
     *
     * Older repo-mediaarea builds now ship control.tar.zst which dpkg on
     * Debian 10 cannot unpack. If the package is installed, apt operations
     * like dselect-upgrade or --fix-broken will repeatedly attempt to
     * upgrade it and fail with a dpkg-deb compression error. Proactively
     * removing the package and marking its selection state as "deinstall"
     * prevents these failures while keeping the MediaArea repository itself
     * configured via standard templates.
     */
    function pmssCleanupMediaareaBootstrapPackage(): void
    {
        $status = trim((string) @shell_exec('dpkg-query -W -f=${Status} repo-mediaarea 2>/dev/null'));
        if ($status === '' || stripos($status, 'not-installed') !== false) {
            return;
        }

        // Best-effort removal: handles installed and reinst-required states
        // without depending on newer zstd-based .debs being present.
        runStep(
            'Removing legacy MediaArea bootstrap package (repo-mediaarea)',
            'dpkg --remove --force-remove-reinstreq repo-mediaarea || true'
        );

        // Ensure future dselect-based runs keep the package absent.
        $setSelection = "printf '%s\\t%s\\n' 'repo-mediaarea' 'deinstall' | dpkg --set-selections";
        runStep('Marking repo-mediaarea for deinstallation', $setSelection);
    }
}

if (!function_exists('pmssMigrateLegacyLocalnet')) {
    /**
     * Move the legacy localnet file into the configuration directory.
     */
    function pmssMigrateLegacyLocalnet(): void
    {
        if (file_exists('/etc/seedbox/localnet') && !file_exists('/etc/seedbox/config/localnet')) {
            runStep('Migrating legacy localnet configuration', 'mv /etc/seedbox/localnet /etc/seedbox/config/localnet');
        }
    }
}
