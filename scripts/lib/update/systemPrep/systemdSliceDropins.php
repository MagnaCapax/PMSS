<?php
/** Managed slice shadows and per-user manager drop-ins. @license GPL-3.0-only */

/** Converge manager descriptors and log isolation with one managed-write metadata policy. */
function pmssSystemdUserManagerDropinsInstall(array $policy, callable $log): void
{
    $specs = [];
    if (array_key_exists('limitNoFileSoft', $policy) || array_key_exists('limitNoFileHard', $policy)) {
        $soft = is_numeric($policy['limitNoFileSoft'] ?? null) ? max(0, (int)$policy['limitNoFileSoft']) : 0;
        $hard = is_numeric($policy['limitNoFileHard'] ?? null) ? max(0, (int)$policy['limitNoFileHard']) : 0;
        if ($soft === 0 && $hard === 0) {
            $log('[SKIP] No LimitNOFILE values found in cgroup policy');
        } else {
            $soft = $soft ?: $hard;
            $hard = max($soft, $hard);
            $specs[] = ['20-pmss-limits.conf',
                "# PMSS: per-user manager descriptor limits from cgroup.policy.php\n[Service]\nLimitNOFILE={$soft}:{$hard}\n",
                '', ' with LimitNOFILE='.$soft.':'.$hard];
        }
    }
    // Log namespaces stay unconditional, after the optional descriptor artifact.
    $specs[] = ['30-pmss-log-namespace.conf',
        "# PMSS: isolate per-user manager logs in dedicated namespaces\n[Service]\nLogNamespace=user-%i\n",
        'log namespace ', ' with LogNamespace=user-%i'];
    foreach ($specs as [$name, $body, $failureLabel, $successSuffix]) {
        $dropDir = pmssResolvePathFromEnv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR', '/etc/systemd/system/user@.service.d');
        $target = $dropDir.'/'.$name;
        pmssRefreshManagedPathFile($target, $body, 'systemd drop-in', $log, [
            'directoryFailureMessage' => '[WARN] Failed to create user@.service drop-in dir '.$dropDir,
            'writeFailureMessage' => '[WARN] Failed to install user@.service '.$failureLabel.'drop-in '.$target,
            'successMessage' => 'Installed '.$target.$successSuffix,
        ]);
    }
}

/** Shadow a legacy vendor cap by filename, preserving the existing trim comparison. */
function pmssSystemdSliceShadowInstall(string $dropDir, int $tasksMax, bool $sawLegacyVendorDropin, callable $log): void
{
    // If we observed the legacy vendor drop-in, create an /etc shadow with
    // the same filename. systemd merges drop-ins by filename across all
    // search paths, so /etc will override /usr/lib and prevent the 250 cap
    // from creeping back in after package updates.
    $shadowPath = $dropDir.'/99-pmss.conf';
    if ($sawLegacyVendorDropin || is_file($shadowPath)) {
        $shadow = "# PMSS: override legacy TasksMax cap (shadow 99-pmss.conf)\n[Slice]\nTasksMax=".$tasksMax."\n";
        $existing = @file_get_contents($shadowPath);
        if (
            ($existing === false || trim((string) $existing) !== trim($shadow))
            && pmssRefreshManagedPathFile($shadowPath, $shadow, 'systemd drop-in', $log, [
                'writeFailureMessage' => '[WARN] Failed to write '.$shadowPath.' TasksMax override (legacy shadow)',
            ])
        ) {
            $log('Installed '.$shadowPath.' TasksMax override (legacy shadow)');
        }
    }
}
