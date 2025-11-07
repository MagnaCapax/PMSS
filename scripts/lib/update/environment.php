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
    function pmssApplyDpkgSelections(?int $distroVersion = null): bool
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

        runStep('Refreshing apt cache before dpkg selection', aptCmd('update'));
        runStep('Refreshing dpkg availability database', 'apt-cache dumpavail | dpkg --merge-avail');

        $selectionPath = $selections;
        $tmpSelection  = null;
        $lines         = @file($selections, FILE_IGNORE_NEW_LINES);
        $success       = true;
        $warnings      = false;
        if ($lines !== false) {
            $sanitised = [];
            foreach ($lines as $idx => $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', $trimmed);
                if (count($parts) < 2) {
                    if (function_exists('logmsg')) {
                        logmsg(sprintf('[WARN] Ignoring malformed dpkg selection line %d: %s', $idx + 1, $trimmed));
                    }
                    $warnings = true;
                    continue;
                }
                $package = $parts[0];
                $state   = $parts[1];
                // Skip problematic or deprecated packages from baseline
                if (in_array($package, ['repo-mediaarea', 'repo-mediaarea-snapshots'], true)) {
                    if (function_exists('logmsg')) {
                        logmsg('[SKIP] Ignoring baseline package '.$package.' (handled via explicit repository setup)');
                    }
                    $warnings = true;
                    continue;
                }
                if (!preg_match('/^[a-z0-9.+:-]+$/i', $package) || !preg_match('/^(install|hold|purge|deinstall)$/i', $state)) {
                    if (function_exists('logmsg')) {
                        logmsg(sprintf('[WARN] Invalid dpkg selection entry at line %d: %s', $idx + 1, $trimmed));
                    }
                    $warnings = true;
                    continue;
                }
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
        }

        // Explicitly clear problematic vendor repo packages from selections so dselect-upgrade
        // will not attempt to install them from cache even if a previous run queued them.
        $clearSelections = "printf 'repo-mediaarea\tdeinstall\nrepo-mediaarea-snapshots\tdeinstall\n' | dpkg --set-selections";
        runStep('Clearing repo-mediaarea selections', $clearSelections);

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

        if ($warnings && function_exists('logmsg')) {
            logmsg('[WARN] Dpkg selection baseline contained ignored entries; proceeding with remaining packages');
        }

        return $success;
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
