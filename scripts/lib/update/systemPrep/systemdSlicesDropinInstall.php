<?php
/**
 * Systemd user slice drop-in renderer for system preparation.
 *
 * Kept separate from the orchestration wrapper so the caller stays small.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/hostResourcesDetect.php';
require_once __DIR__.'/systemdUserManagerNoFileLimitInstall.php';

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
        $policy = [];
        if (file_exists($policyFile)) {
            $loaded = @include $policyFile;
            if (is_array($loaded)) { $policy = $loaded; }
        }

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
        $defaultTasksMax = 512 * $scaleBase;
        $defaultTasksMax = max(2048, min(16384, $defaultTasksMax));
        $tasksMax = $defaultTasksMax;
        if (isset($policy['tasksMax']) && is_numeric($policy['tasksMax'])) {
            $tasksMax = (int)$policy['tasksMax'];
        }

        // Calculate default CPUQuota: 85% of total logical cores (threads).
        // Fallback to 600% (6 cores) if detection fails.
        $defaultQuota = ($cpuThreads > 0) ? ($cpuThreads * 85) : 600;

        $cpuQuotaVal = $defaultQuota;
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
                if (!is_array($def)) continue;
                $src = trim((string)@shell_exec('findmnt -no SOURCE '.escapeshellarg($mount).' 2>/dev/null'));
                if ($src === '') continue;
                if (isset($def['ioWeight']) && is_numeric($def['ioWeight'])) {
                    if ($mode === 'v2') {
                        $append[] = 'IODeviceWeight='.$src.' '.(int)$def['ioWeight'];
                    } else {
                        $skippedDeviceWeights = true;
                    }
                }
                if (isset($def['readBw']))  { $append[] = 'IOReadBandwidthMax='.$src.' '.$def['readBw']; }
                if (isset($def['writeBw'])) { $append[] = 'IOWriteBandwidthMax='.$src.' '.$def['writeBw']; }
                if (isset($def['readIops'])) { $append[] = 'IOReadIOPSMax='.$src.' '.$def['readIops']; }
                if (isset($def['writeIops'])) { $append[] = 'IOWriteIOPSMax='.$src.' '.$def['writeIops']; }
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
