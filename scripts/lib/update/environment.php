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
require_once __DIR__.'/dpkgSelections.php';
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
            $runtimeVersion = function_exists('pmssDistroVersionFromEnv')
                ? pmssDistroVersionFromEnv($distroVersion)
                : (int) ($distroVersion ?? 0);
            $selectionPlan = pmssDpkgSelectionsSanitise($lines, $runtimeVersion);
            $sanitised = $selectionPlan['sanitised'];
            $warnings = (bool) $selectionPlan['warnings'];

            if (!empty($sanitised)) {
                $tmpSelection = pmssWriteSanitisedDpkgSelectionsTempFile($sanitised);
                if ($tmpSelection === null) {
                    logMessage('[ERROR] Refusing to apply raw dpkg selections baseline after sanitized baseline staging failed');
                    return false;
                }
                $selectionPath = $tmpSelection;
            }

            pmssDpkgSelectionsLogSummary($selectionPlan);
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
