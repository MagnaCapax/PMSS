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
require_once __DIR__.'/dpkgRecovery.php';
require_once __DIR__.'/managedPath.php';

/** Treat any known dpkg state except not-installed as legacy package presence. */
function pmssDpkgStatusIndicatesPresent(string $status): bool
{
    $status = trim($status);
    return $status !== '' && stripos($status, 'not-installed') === false;
}

/** Read the legacy MediaArea package state without emitting missing-package errors. */
function pmssRepoMediaareaDpkgStatus(): string
{
    // Quote the format: unquoted, sh expands ${Status} to empty and dpkg-query prints nothing.
    return (string) @shell_exec(pmssLockChildClosePrefix().'dpkg-query -W -f='.escapeshellarg('${Status}').' repo-mediaarea 2>/dev/null');
}

/**
 * Remove the timestamped sources.list copies left by the old MediaArea prune, keeping the newest.
 *
 * Until #1216 the prune commented out the templates' own MediaArea line and copied
 * sources.list first, and the next template apply put the line back, so every update
 * added a copy. Only regular files named sources.list.pmss-backup-<14 digits> qualify;
 * the plain sources.list.pmss-backup written by pmssSafeWriteSources() is left alone.
 *
 * @return int number of copies removed
 */
function pmssPruneSourcesListBackups(string $aptDir = '/etc/apt'): int
{
    $copies = [];
    foreach (glob($aptDir.'/sources.list.pmss-backup-*') ?: [] as $path) {
        if (preg_match('~/sources\.list\.pmss-backup-[0-9]{14}$~D', $path) === 1 && is_file($path) && !is_link($path)) {
            $copies[] = $path;
        }
    }
    sort($copies, SORT_STRING); // fixed-width YmdHis names sort oldest first
    array_pop($copies);
    $removed = 0;
    foreach ($copies as $path) {
        if (@unlink($path)) {
            $removed++;
        }
    }
    if ($removed > 0) {
        logMessage(sprintf('Removed %d older apt sources.list.pmss-backup-* copies (kept the newest)', $removed));
    }
    return $removed;
}

/**
     * Remove lingering MediaArea apt sources and cached bootstrap packages.
     *
     * Older repo-mediaarea packages now use control.tar.zst and are not needed.
     * The MediaArea line in /etc/apt/sources.list belongs to the apt templates
     * (template.sources.*), which own that file, so it is not touched here (#1216).
     * Best-effort cleanup keeps dpkg baseline apply from tripping over stale .list
     * files or cached .debs.
     */
    function pmssPruneLegacyMediaArea(): void
    {
        runStep('Removing legacy MediaArea apt list files', 'rm -f /etc/apt/sources.list.d/mediaarea*.list');
        runStep('Removing legacy MediaArea apt preferences/keys', 'rm -f /etc/apt/preferences.d/mediaarea* /etc/apt/trusted.gpg.d/mediaarea*.gpg');
        pmssPruneSourcesListBackups();

        if (pmssDpkgStatusIndicatesPresent(pmssRepoMediaareaDpkgStatus())) {
            runStep('Purging legacy MediaArea package', aptCmd('purge -y repo-mediaarea || true'));
            runStep('Holding legacy MediaArea package to block reinstalls', pmssAptDpkgEnvPrefix().' apt-mark hold repo-mediaarea || true');
        }
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
        $hasSystemd = pmssSystemdRuntimeAvailable();
        if ($hasSystemd) {
            if (pmssSystemdUnitState('is-enabled', 'proftpd.service') === 'masked') {
                runCommand('systemctl unmask proftpd.service');
            }
        }

        $rc = runStep('Completing pending dpkg configuration', dpkgCmd('--force-confdef --force-confold --configure -a'));
        if ($rc !== 0 && !pmssWaitForDpkgLocks(60, 1, 'package recovery')) {
            return;
        }
        if ($rc !== 0 && $hasSystemd) {
            runStep('Unmasking proftpd for dpkg retry', 'systemctl unmask proftpd.service || true');
        }
        if ($rc !== 0) {
            runStep('Retrying proftpd configure', dpkgCmd('--force-confdef --force-confold --configure proftpd-core proftpd-mod-crypto proftpd-mod-wrap proftpd-basic').' || true');
        }
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
        runStep(
            'Refreshing dpkg availability database',
            pmssAptDpkgEnvPrefix().' apt-cache dumpavail | '.dpkgCmd('--merge-avail')
        );

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

        $cmd = sprintf('%s < %s', dpkgCmd('--set-selections'), escapeshellarg($selectionPath));
        $success = $success && (runStep('Applying dpkg selection baseline', $cmd) === 0);

        $installCmd = aptCmd('dselect-upgrade -y');
        $rc = runStep('Installing packages from selection baseline', $installCmd);
        if ($rc !== 0) {
            $retryRc = pmssRecoverDpkgBaseline('Attempting apt fix-broken install (dpkg baseline)')
                ? runStep('Retrying package selection install', $installCmd) : 1;
            if ($retryRc !== 0) {
                logmsg('[ERROR] Package baseline installation still failing after retry');
            }
            $success = $success && ($retryRc === 0);
        }

        if ($tmpSelection !== null) {
            @unlink($tmpSelection);
        }

        // GH#643: after the baseline install, HOLD fuse3 so an explicit `apt-get install fuse`
        // (the legacy rootless-docker setuptool vector) ABORTS instead of cascading fuse3 +
        // fuse-overlayfs out and hard-downing rootless Docker server-wide. apt refuses to remove a
        // held, non-named package without --allow-change-held-packages (apt-get(8)), and the
        // aggressor uses plain `-y`. The set-selections baseline above clears any prior hold, so
        // this re-asserts it every update. Guarded to an installed fuse3 -> no-op on Debian 10 /
        // non-docker hosts. The reactive heal stays the `fuse deinstall` + `fuse3`/`fuse-overlayfs
        // install` lines in the selection baseline (mirrors the repo-mediaarea hold above).
        if (trim((string) @shell_exec("dpkg-query -W -f='\${Status}' fuse3 2>/dev/null")) === 'install ok installed') {
            runStep('Holding fuse3 to block the fuse2 conflict-removal cascade (GH#643)',
                pmssAptDpkgEnvPrefix().' apt-mark hold fuse3 || true');
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
