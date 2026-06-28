<?php
/**
 * Systemd user slice setup orchestration for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../managedPath.php';
require_once dirname(__DIR__, 2).'/cgroup/policy.php';
require_once dirname(__DIR__).'/../runtime.php';

/** Render untrusted policy/findmnt fragments safely in single-line logs. */
function pmssSystemdLogValue($value): string
{
    $safe = str_replace(["\r", "\n", "\0"], ['\\r', '\\n', '\\0'], (string) $value);
    return strlen($safe) > 160 ? substr($safe, 0, 157).'...' : $safe;
}

/**
 * Render and install LimitNOFILE drop-in for user@.service when configured.
 */
function pmssSystemdUserManagerNoFileLimitInstall(array $policy, callable $log): void
{
    if (!array_key_exists('limitNoFileSoft', $policy) && !array_key_exists('limitNoFileHard', $policy)) {
        return;
    }

    $soft = (isset($policy['limitNoFileSoft']) && is_numeric($policy['limitNoFileSoft'])) ? max(0, (int)$policy['limitNoFileSoft']) : 0;
    $hard = (isset($policy['limitNoFileHard']) && is_numeric($policy['limitNoFileHard'])) ? max(0, (int)$policy['limitNoFileHard']) : 0;
    if ($soft === 0 && $hard === 0) {
        $log('[SKIP] No LimitNOFILE values found in cgroup policy');
        return;
    }

    $soft = $soft ?: $hard;
    $hard = max($soft, $hard);
    $dropDir = pmssResolvePathFromEnv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR', '/etc/systemd/system/user@.service.d');
    if (!pmssDirEnsureExists($dropDir, 0755)) {
        $log('[WARN] Failed to create user@.service drop-in dir '.$dropDir);
        return;
    }

    $target = $dropDir.'/20-pmss-limits.conf';
    $body = "# PMSS: per-user manager descriptor limits from cgroup.policy.php\n[Service]\nLimitNOFILE={$soft}:{$hard}\n";
    if (pmssWriteManagedPathFile($target, $body, 'systemd drop-in', $log, null, null, 0644, '[WARN] Failed to install user@.service drop-in '.$target)) { $log(sprintf('Installed %s with LimitNOFILE=%d:%d', $target, $soft, $hard)); }
}

    /**
     * Install tuned systemd slice overrides when missing.
     */
    function pmssEnsureSystemdSlices(?callable $logger = null): void
    {
        $log = $logger ?: 'logMessage';
        // Avoid touching the host systemd manager in test mode so dev tests stay hermetic.
        $skipSystemctl = pmssTestModeEnabled();

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

        if (!file_exists($tpl)) {
            $log('[WARN] Slice template missing: '.$tpl);
            return;
        }

        // Compute sane defaults with constraints
        $totalMiB     = pmssTotalMemMiB();
        $minHighMiB   = 250; // minimum MemoryHigh
        // Allow policy override via PHP array file: cgroup.policy.php returning ['memoryHighMiB'=>..,'memoryMaxMiB'=>..,'cpuWeight'=>..,'ioWeight'=>..,'tasksMax'=>..]
        $policy = pmssCgroupPolicyLoad($cfgDir);

        $defaultHigh  = max($minHighMiB, (int)floor($totalMiB * 0.10)); // default ~10% of RAM
        $maxCapMiB    = (int)floor($totalMiB * 0.95); // MemoryMax never above 95% of total
        $policyHigh   = isset($policy['memoryHighMiB']) && is_numeric($policy['memoryHighMiB']) ? (int)$policy['memoryHighMiB'] : $defaultHigh;
        $policyHigh   = max($minHighMiB, $policyHigh);
        $calcMax      = isset($policy['memoryMaxMiB']) && is_numeric($policy['memoryMaxMiB']) ? (int)$policy['memoryMaxMiB'] : (int)floor($policyHigh * 1.25);
        $calcMax      = min($calcMax, $maxCapMiB);
        $cpuWeight    = isset($policy['cpuWeight']) && is_numeric($policy['cpuWeight']) ? (int)$policy['cpuWeight'] : 200;
        $ioWeight     = isset($policy['ioWeight']) && is_numeric($policy['ioWeight']) ? (int)$policy['ioWeight'] : 200;

        // Derive a reasonable default per-user TasksMax based on host capacity.
        // systemd TasksMax counts tasks (threads), not just processes.
        $cpuThreads = pmssTotalCpuThreads();
        $memGiB = $totalMiB > 0 ? (int)ceil($totalMiB / 1024) : 0;
        $scaleBase = max($cpuThreads, $memGiB);
        $defaultTasksMax = max(2048, min(16384, 512 * $scaleBase));
        $tasksMax = (isset($policy['tasksMax']) && is_numeric($policy['tasksMax'])) ? (int) $policy['tasksMax'] : $defaultTasksMax;

        // Calculate default CPUQuota: 85% of total logical cores (threads).
        // Fallback to 600% (6 cores) if detection fails.
        $cpuQuotaVal = ($cpuThreads > 0) ? ($cpuThreads * 85) : 600;
        if (isset($policy['cpuQuotaPercent'])) {
            $pVal = $policy['cpuQuotaPercent'];
            if (is_string($pVal) && strtolower($pVal) === 'infinity') {
                $cpuQuotaVal = 'infinity';
            } elseif (is_numeric($pVal)) {
                $cpuQuotaVal = (int)$pVal;
            }
        }

        $repl = [
            '%%USER_CGROUP_MEMORY_HIGH%%' => (string)$policyHigh,
            '%%USER_CGROUP_MEMORY_MAX%%'  => (string)$calcMax,
            '%%USER_CGROUP_CPU_WEIGHT%%'  => (string)$cpuWeight,
            '%%USER_CGROUP_IO_WEIGHT%%'   => (string)$ioWeight,
            '%%USER_CGROUP_TASKS_MAX%%'   => (string)$tasksMax,
            '%%USER_CGROUP_CPU_QUOTA%%'   => ($cpuQuotaVal === 'infinity') ? 'infinity' : $cpuQuotaVal.'%',
            '%%USER_CGROUP_IO_DEVICE_LATENCY%%' => '',
        ];
        if ($mode === 'v2' && isset($policy['ioLatencyMs']) && is_numeric($policy['ioLatencyMs']) && (int) $policy['ioLatencyMs'] > 0) {
            $homeDevice = pmssCgroupPolicyMountSourceResolve('/home');
            if ($homeDevice !== '' && pmssCgroupPolicyDeviceTargetIsSafe($homeDevice)) {
                $repl['%%USER_CGROUP_IO_DEVICE_LATENCY%%'] = 'IODeviceLatencyTargetSec='.$homeDevice.' '.(int) $policy['ioLatencyMs'].'ms';
            } elseif ($homeDevice !== '') {
                $log('[WARN] Unsafe /home backing device for IODeviceLatencyTargetSec: '.pmssSystemdLogValue($homeDevice));
            } else {
                $log('[WARN] Unable to resolve /home backing device for IODeviceLatencyTargetSec');
            }
        } elseif ($mode !== 'v2' && isset($policy['ioLatencyMs']) && is_numeric($policy['ioLatencyMs']) && (int) $policy['ioLatencyMs'] > 0) {
            $log('[SKIP] IODeviceLatencyTargetSec skipped on cgroup v1');
        }
        $raw = strtr((string)@file_get_contents($tpl), $repl);
        // Append per-mount device throttles and weights from policy
        if (isset($policy['mounts']) && is_array($policy['mounts'])) {
            $append = [];
            $skippedDeviceWeights = false;
            foreach ($policy['mounts'] as $mount => $def) {
                if (!is_array($def) || ($src = pmssCgroupPolicyMountSourceResolve((string) $mount)) === '') {
                    continue;
                }
                if (!pmssCgroupPolicyDeviceTargetIsSafe($src)) {
                    $log('[WARN] Unsafe backing device for cgroup policy mount '.pmssSystemdLogValue($mount).': '.pmssSystemdLogValue($src));
                    continue;
                }
                if (isset($def['ioWeight']) && is_numeric($def['ioWeight'])) {
                    $skippedDeviceWeights = $skippedDeviceWeights || $mode !== 'v2';
                }
                $unsafeKeys = [];
                $append = array_merge($append, pmssCgroupPolicyIoPairs($def, $src, $mode === 'v2', true, $unsafeKeys));
                foreach ($unsafeKeys as $policyKey) {
                    $log('[WARN] Unsafe '.$policyKey.' value for cgroup policy mount '.pmssSystemdLogValue($mount).', skipping');
                }
            }
            if (!empty($append)) {
                $raw .= "\n".implode("\n", $append)."\n";
            }
            if ($skippedDeviceWeights) {
                $log('[SKIP] Per-device IO weights skipped on cgroup v1 (blkio.weight_device unsupported)');
            }
        }

        // Atomic write to avoid race conditions where the file is briefly missing
        if (!pmssWriteManagedPathFile($target, $raw, 'systemd drop-in', $log, null, null, 0644, '[WARN] Failed to atomically replace user-.slice drop-in '.$target)) {
            return;
        }

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
                && pmssWriteManagedPathFile($shadowPath, $shadow, 'systemd drop-in', $log, null, null, 0644, '[WARN] Failed to write '.$shadowPath.' TasksMax override (legacy shadow)')
            ) {
                $log('Installed '.$shadowPath.' TasksMax override (legacy shadow)');
            }
        }

        // Optional user@ manager descriptor caps are policy-driven and
        // intentionally separate from the user-.slice resource controls.
        pmssSystemdUserManagerNoFileLimitInstall($policy, $log);

        // Keep user manager logs isolated by namespace to reduce
        // cross-tenant journald mixing on shared hosts.
        $userAtDropDir = pmssResolvePathFromEnv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR', '/etc/systemd/system/user@.service.d');
        if (!pmssDirEnsureExists($userAtDropDir, 0755)) {
            $log('[WARN] Failed to create user@.service drop-in dir '.$userAtDropDir);
        } else {
            $userAtTarget = $userAtDropDir.'/30-pmss-log-namespace.conf';
            $userAtBody = "# PMSS: isolate per-user manager logs in dedicated namespaces\n[Service]\nLogNamespace=user-%i\n";
            if (pmssWriteManagedPathFile($userAtTarget, $userAtBody, 'systemd drop-in', $log, null, null, 0644, '[WARN] Failed to install user@.service log namespace drop-in '.$userAtTarget)) { $log('Installed '.$userAtTarget.' with LogNamespace=user-%i'); }
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
        pmssDirEnsureExists($rootDir, 0755);
        // Use a suffix that sorts after legacy 99-pmss.conf drop-ins so root
        // remains unlimited even when a stale vendor file exists.
        $rootDrop = $rootDir.'/99-zz-pmss-unlimited.conf';
        pmssWriteManagedPathFile($rootDrop, "[Slice]\nMemoryHigh=infinity\nMemoryMax=infinity\nTasksMax=infinity\n", 'systemd root slice drop-in', $log, null, null, 0644, '[WARN] Failed to install root slice drop-in '.$rootDrop);
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
