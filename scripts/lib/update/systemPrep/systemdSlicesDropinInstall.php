<?php
/**
 * Systemd user slice drop-in renderer for system preparation.
 *
 * Keeps slice and user-manager drop-in rendering together so the caller stays small
 * without bouncing through extra include hops.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/hostResourcesDetect.php';
require_once dirname(__DIR__).'/runtime/commands.php';

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
    if (!is_dir($dropDir) && !@mkdir($dropDir, 0755, true)) {
        $log('[WARN] Failed to create user@.service drop-in dir '.$dropDir);
        return;
    }

    $target = $dropDir.'/20-pmss-limits.conf';
    $body = "# PMSS: per-user manager descriptor limits from cgroup.policy.php\n[Service]\nLimitNOFILE={$soft}:{$hard}\n";
    if (@file_put_contents($tmpTarget = $target.'.tmp', $body) === false) {
        $log('[WARN] Failed to write temp user@.service drop-in '.$tmpTarget);
        return;
    }
    @chmod($tmpTarget, 0644);

    if (!@rename($tmpTarget, $target)) {
        $log('[WARN] Failed to install user@.service drop-in '.$target);
        @unlink($tmpTarget);
        return;
    }

    $log(sprintf('Installed %s with LimitNOFILE=%d:%d', $target, $soft, $hard));
}

/**
 * Install user@ manager log namespace drop-in.
 *
 * This keeps per-user manager/service logs in dedicated journald
 * namespaces, reducing cross-tenant log mixing in shared environments.
 */
function pmssSystemdUserManagerLogNamespaceInstall(callable $log): void
{
    $dropDir = pmssResolvePathFromEnv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR', '/etc/systemd/system/user@.service.d');
    if (!is_dir($dropDir) && !@mkdir($dropDir, 0755, true)) {
        $log('[WARN] Failed to create user@.service drop-in dir '.$dropDir);
        return;
    }

    $target = $dropDir.'/30-pmss-log-namespace.conf';
    $body = "# PMSS: isolate per-user manager logs in dedicated namespaces\n[Service]\nLogNamespace=user-%i\n";
    if (@file_put_contents($tmpTarget = $target.'.tmp', $body) === false) {
        $log('[WARN] Failed to write temp user@.service log namespace drop-in '.$tmpTarget);
        return;
    }
    @chmod($tmpTarget, 0644);

    if (!@rename($tmpTarget, $target)) {
        $log('[WARN] Failed to install user@.service log namespace drop-in '.$target);
        @unlink($tmpTarget);
        return;
    }

    $log('Installed '.$target.' with LogNamespace=user-%i');
}

    /**
     * Render and install the user-.slice drop-in.
     *
     * @return int|null The computed TasksMax, or null on failure.
     */
    function pmssSystemdSlicesDropinInstall(
        string $tpl,
        string $cfgDir,
        string $mode,
        string $dropDir,
        string $target,
        bool $sawLegacyVendorDropin,
        callable $log
    ): ?int {
        if (!file_exists($tpl)) {
            $log('[WARN] Slice template missing: '.$tpl);
            return null;
        }

        // Compute sane defaults with constraints
        $totalMiB     = pmssTotalMemMiB();
        $minHighMiB   = 250; // minimum MemoryHigh
        // Allow policy override via PHP array file: cgroup.policy.php returning ['memoryHighMiB'=>..,'memoryMaxMiB'=>..,'cpuWeight'=>..,'ioWeight'=>..,'tasksMax'=>..]
        $policyFile = $cfgDir.'/cgroup.policy.php';
        $policy = (is_array($loaded = file_exists($policyFile) ? @include $policyFile : null)) ? $loaded : [];

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
        ];
        $raw = strtr((string)@file_get_contents($tpl), $repl);
        // Append per-mount device throttles and weights from policy
        if (isset($policy['mounts']) && is_array($policy['mounts'])) {
            $append = [];
            $skippedDeviceWeights = false;
            foreach ($policy['mounts'] as $mount => $def) {
                if (!is_array($def)
                    || ($src = trim((string) @shell_exec('findmnt -no SOURCE '.escapeshellarg($mount).' 2>/dev/null'))) === '') {
                    continue;
                }
                if (isset($def['ioWeight']) && is_numeric($def['ioWeight'])) {
                    if ($mode === 'v2') {
                        $append[] = 'IODeviceWeight='.$src.' '.(int)$def['ioWeight'];
                    } else {
                        $skippedDeviceWeights = true;
                    }
                }
                foreach (['readBw' => 'IOReadBandwidthMax', 'writeBw' => 'IOWriteBandwidthMax', 'readIops' => 'IOReadIOPSMax', 'writeIops' => 'IOWriteIOPSMax'] as $policyKey => $directive) {
                    if (isset($def[$policyKey])) { $append[] = $directive.'='.$src.' '.$def[$policyKey]; }
                }
            }
            if (!empty($append)) { $raw .= "\n".implode("\n", $append)."\n"; }
            if ($skippedDeviceWeights) {
                $log('[SKIP] Per-device IO weights skipped on cgroup v1 (blkio.weight_device unsupported)');
            }
        }

        // Atomic write to avoid race conditions where the file is briefly missing
        $tmpTarget = $target . '.tmp';
        if (@file_put_contents($tmpTarget, $raw) === false) {
            $log('[WARN] Failed to write temp user-.slice drop-in '.$tmpTarget);
            return null;
        }
        @chmod($tmpTarget, 0644);
        if (!@rename($tmpTarget, $target)) {
             $log('[WARN] Failed to atomically replace user-.slice drop-in '.$target);
             unlink($tmpTarget);
             return null;
        }

        // If we observed the legacy vendor drop-in, create an /etc shadow with
        // the same filename. systemd merges drop-ins by filename across all
        // search paths, so /etc will override /usr/lib and prevent the 250 cap
        // from creeping back in after package updates.
        $shadowPath = $dropDir.'/99-pmss.conf';
        if ($sawLegacyVendorDropin || is_file($shadowPath)) {
            $shadow = "# PMSS: override legacy TasksMax cap (shadow 99-pmss.conf)\n[Slice]\nTasksMax=".$tasksMax."\n";
            $existing = @file_get_contents($shadowPath);
            if ($existing === false || trim((string) $existing) !== trim($shadow)) {
                if (@file_put_contents($shadowPath, $shadow) !== false) {
                    @chmod($shadowPath, 0644);
                    $log('Installed '.$shadowPath.' TasksMax override (legacy shadow)');
                } else {
                    $log('[WARN] Failed to write '.$shadowPath.' TasksMax override (legacy shadow)');
                }
            }
        }

        // Optional user@ manager descriptor caps are policy-driven and
        // intentionally separate from the user-.slice resource controls.
        pmssSystemdUserManagerNoFileLimitInstall($policy, $log);

        // Keep user manager logs isolated by namespace to reduce
        // cross-tenant journald mixing on shared hosts.
        pmssSystemdUserManagerLogNamespaceInstall($log);

        return $tasksMax;
    }
