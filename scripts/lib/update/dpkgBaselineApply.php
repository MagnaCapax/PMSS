<?php
/** Dpkg baseline application controller; environment.php retains the legacy include. */
require_once __DIR__.'/dpkgSelections.php';
require_once __DIR__.'/dpkgRecovery.php';

/**
 * Apply the baseline dpkg selection snapshot so required packages stay present.
 *
 * @return bool True when the baseline was parsed and applied successfully.
 */
function pmssApplyDpkgSelections(?int $distroVersion = null, bool $skipUpdate = false): bool
{
    $selectionPath = pmssSelectDpkgSelectionsBaseline($distroVersion);
    if ($selectionPath === null) {
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

    $tmpSelection = null;
    try {
        $lines = @file($selectionPath, FILE_IGNORE_NEW_LINES);
        if ($lines !== false) {
            $runtimeVersion = function_exists('pmssDistroVersionFromEnv')
                ? pmssDistroVersionFromEnv($distroVersion)
                : (int) ($distroVersion ?? 0);
            $selectionPlan = pmssDpkgSelectionsSanitise($lines, $runtimeVersion);
            if (!empty($selectionPlan['sanitised'])) {
                $tmpSelection = pmssWriteSanitisedDpkgSelectionsTempFile($selectionPlan['sanitised']);
                if ($tmpSelection === null) {
                    logMessage('[ERROR] Refusing to apply raw dpkg selections baseline after sanitized baseline staging failed');
                    return false;
                }
                $selectionPath = $tmpSelection;
            }

            pmssDpkgSelectionsLogSummary($selectionPlan);
        }

        $success = runStep('Applying dpkg selection baseline',
            sprintf('%s < %s', dpkgCmd('--set-selections'), escapeshellarg($selectionPath))) === 0;

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
    } finally {
        // Summary, command and recovery throwables must also release our staged file.
        if ($tmpSelection !== null) {
            @unlink($tmpSelection);
        }
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

    if (!empty($selectionPlan['warnings'])) {
        pmssLogStatus('WARN', 'Dpkg selection baseline contained ignored entries; proceeding with remaining packages', 0);
    }

    return $success;
}
