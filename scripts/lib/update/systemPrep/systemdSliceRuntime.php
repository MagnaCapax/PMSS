<?php
/** Live slice refresh after managed configuration has been published. @license GPL-3.0-only */

/** Reload root protection, then lift only active slices still carrying the legacy low cap. */
function pmssSystemdSliceRuntimeRefresh(int $tasksMax, bool $skipSystemctl, callable $log): void
{
    if ($skipSystemctl) {
        pmssLogStatus('SKIP', 'Reloading systemd manager configuration (root slice, test mode)', 0);
        return;
    }
    runStep('Reloading systemd manager configuration (root slice)', 'systemctl daemon-reload');

    // Apply updated TasksMax limits to already-running slices without
    // persisting per-user overrides. systemctl daemon-reload does not
    // retroactively reconfigure active slice cgroups, so older hosts may
    // remain stuck on legacy defaults (e.g. 250/512) until reboot.
    runStep(
        'Unlimiting root user slice runtime TasksMax',
        "systemctl set-property --runtime 'user-0.slice' MemoryHigh=infinity MemoryMax=infinity TasksMax=infinity"
    );

    $rc = runCommand("systemctl list-units --type=slice 'user-*.slice' --state=active --no-legend --no-pager", false, $log);
    $stdout = trim((string) ($GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? ''));
    if ($rc !== 0 || $stdout === '') {
        return;
    }
    foreach (pmssConfigTextRows($stdout, 1, []) as $parts) {
        $unit = (string) $parts[0];
        if ($unit === 'user-0.slice' || preg_match('/^user-\d+\.slice$/', $unit) !== 1) {
            continue;
        }

        $showRc = runCommand('systemctl show '.escapeshellarg($unit).' -p TasksMax', false, $log);
        $tasksLine = trim((string) ($GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? ''));
        if ($showRc !== 0 || strpos($tasksLine, 'TasksMax=') !== 0
            || !ctype_digit($current = trim(substr($tasksLine, strlen('TasksMax='))))) {
            continue;
        }
        $currentInt = (int) $current;
        if ($currentInt < 1 || $currentInt >= 2048 || $tasksMax <= $currentInt) {
            continue;
        }
        runStep(
            'Refreshing user slice runtime TasksMax :: '.$unit,
            'systemctl set-property --runtime '.escapeshellarg($unit).' TasksMax='.$tasksMax
        );
    }
}
