<?php
/**
 * Systemd user slice setup orchestration for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/cgroupsEnsureConfigured.php';
require_once __DIR__.'/systemdSlicesDropinInstall.php';

    /**
     * Install tuned systemd slice overrides when missing.
     */
    function pmssEnsureSystemdSlices(?callable $logger = null): void
    {
        $log = pmssSelectLogger($logger);
        // Avoid touching the host systemd manager in test mode so dev tests stay hermetic.
        $skipSystemctl = (defined('PMSS_TEST_MODE') && PMSS_TEST_MODE === true);

        // Drop-in management must target /etc paths only; avoid vendor dirs.
        // Some legacy hosts still carry a vendor drop-in at:
        //   /usr/lib/systemd/system/user-.slice.d/99-pmss.conf
        //   /lib/systemd/system/user-.slice.d/99-pmss.conf
        // which can override PMSS settings (including TasksMax) and can even
        // reappear after dpkg updates. Remove it when found.
        $sawLegacyVendorDropin = false;
        foreach ([
            '/usr/lib/systemd/system/user-.slice.d/99-pmss.conf',
            '/lib/systemd/system/user-.slice.d/99-pmss.conf',
        ] as $legacyPath) {
            if ($skipSystemctl || !is_file($legacyPath)) {
                continue;
            }
            $sawLegacyVendorDropin = true;
            $log((@unlink($legacyPath)
                ? '[WARN] Removed legacy vendor systemd drop-in '
                : '[WARN] Unable to remove legacy vendor systemd drop-in ').$legacyPath);
        }

        $mode = pmssCgroupMode();
        $dropDir = pmssResolvePathFromEnv('PMSS_SYSTEMD_USER_SLICE_DIR', '/etc/systemd/system/user-.slice.d');
        $target  = $dropDir.'/15-pmss.conf';
        is_dir($dropDir) || runStep('Creating user-.slice drop-in directory', 'install -d -m 0755 '.escapeshellarg($dropDir));

        // Render template based on cgroup mode
        $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
        $tpl = $cfgDir.'/template.cgroup.user-slice.'.($mode === 'v2' ? 'v2' : 'v1').'.conf';

        $tasksMax = pmssSystemdSlicesDropinInstall($tpl, $cfgDir, $mode, $dropDir, $target, $sawLegacyVendorDropin, $log);
        if ($tasksMax === null) {
            return;
        }

        if ($skipSystemctl) {
            pmssLogStatus('SKIP', 'Reloading systemd manager configuration (test mode)', 0);
        } else {
            runStep('Reloading systemd manager configuration', 'systemctl daemon-reload');
        }
        $log(sprintf('Installed %s slice override (mode=%s)', $target, $mode));

        // Ensure root (uid 0) slice is not limited: create user-0 specific
        // override setting infinity/large limits.
        $rootDir = dirname($dropDir).'/user-0.slice.d';
        is_dir($rootDir) || @mkdir($rootDir, 0755, true);
        // Use a suffix that sorts after legacy 99-pmss.conf drop-ins so root
        // remains unlimited even when a stale vendor file exists.
        $rootDrop = $rootDir.'/99-zz-pmss-unlimited.conf';
        @file_put_contents($rootDrop, "[Slice]\nMemoryHigh=infinity\nMemoryMax=infinity\nTasksMax=infinity\n");
        @chmod($rootDrop, 0644);
        @unlink($rootDir.'/99-pmss-unlimited.conf');
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
        foreach (preg_split('/\r?\n/', $stdout) as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            $unit = (string) $parts[0];
            if ($unit === 'user-0.slice' || preg_match('/^user-\d+\.slice$/', $unit) !== 1) {
                continue;
            }

            $showRc = runCommand('systemctl show '.escapeshellarg($unit).' -p TasksMax', false, $log);
            $tasksLine = trim((string) ($GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? ''));
            if ($showRc !== 0 || strpos($tasksLine, 'TasksMax=') !== 0) {
                continue;
            }
            $current = trim(substr($tasksLine, strlen('TasksMax=')));
            if (!ctype_digit($current)) {
                continue;
            }
            $currentInt = (int) $current;
            if ($currentInt < 1 || $currentInt >= 2048 || $tasksMax <= $currentInt) {
                continue;
            }
            runStep(
                'Refreshing user slice runtime TasksMax :: '.$unit,
                sprintf(
                    "systemctl set-property --runtime %s TasksMax=%d",
                    escapeshellarg($unit),
                    $tasksMax
                )
            );
        }
    }
