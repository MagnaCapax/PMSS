<?php
/**
 * Base system preparation helpers executed during update-step2.
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/../runtime.php';

if (!function_exists('pmssCgroupMode')) {
    /**
     * Detect cgroup mode: 'v2', 'v1', or 'unknown'.
     */
    function pmssCgroupMode(): string
    {
        $override = getenv('PMSS_CGROUP_MODE');
        if (is_string($override) && ($override === 'v2' || $override === 'v1')) {
            return $override;
        }

        // Strongest signal: cgroup2 mount present in /proc/self/mountinfo
        $mountinfo = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($mountinfo as $line) {
            // Fields: ... mountpoint ... - fstype source options
            // Look for " - cgroup2 " which unambiguously indicates unified v2
            if (strpos($line, ' - cgroup2 ') !== false) {
                return 'v2';
            }
        }

        // Kernel exposes controllers file only on v2
        if (is_file('/sys/fs/cgroup/cgroup.controllers')) {
            return 'v2';
        }

        // Kernel cmdline override used by systemd to force v1 on newer Debian
        $cmdline = @file_get_contents('/proc/cmdline');
        if (is_string($cmdline) && strpos($cmdline, 'systemd.unified_cgroup_hierarchy=0') !== false) {
            return 'v1';
        }

        // v1 hint: presence of controller directories under /sys/fs/cgroup/
        $v1hints = glob('/sys/fs/cgroup/*', GLOB_ONLYDIR) ?: [];
        foreach ($v1hints as $d) {
            if (basename((string)$d) !== 'unified') {
                return 'v1';
            }
        }

        return 'unknown';
    }
}

if (!function_exists('pmssTotalMemMiB')) {
    /** Return total system memory in MiB (rounded). */
    function pmssTotalMemMiB(): int
    {
        $override = getenv('PMSS_TOTAL_MEM_MIB');
        if (is_string($override) && $override !== '' && ctype_digit($override)) {
            return (int)$override;
        }
        $meminfo = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($meminfo as $line) {
            if (strpos($line, 'MemTotal:') === 0) {
                $kb = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                return (int)round($kb / 1024);
            }
        }
        return 0;
    }
}

if (!function_exists('pmssTotalCpuThreads')) {
    /** Return total logical CPU threads. */
    function pmssTotalCpuThreads(): int
    {
        $override = getenv('PMSS_TOTAL_CPU_THREADS');
        if (is_string($override) && $override !== '' && ctype_digit($override)) {
            return (int)$override;
        }
        // Robust check using /proc/cpuinfo
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo !== false) {
            $count = substr_count($cpuinfo, 'processor');
            if ($count > 0) return $count;
        }
        // Fallback to nproc if available
        $nproc = @shell_exec('nproc');
        if ($nproc !== null) {
            $count = (int)trim($nproc);
            if ($count > 0) return $count;
        }
        return 0;
    }
}

if (!function_exists('pmssEnsureCgroupsConfigured')) {
    /**
     * Guarantee that cgroup mounts and PID limits are configured sanely.
     */
    function pmssEnsureCgroupsConfigured(?callable $logger = null): void
    {
        $log   = pmssSelectLogger($logger);
        $mode = pmssCgroupMode();
        if ($mode === 'v1') {
            $fstab = @file_get_contents('/etc/fstab');
            if ($fstab === false || strpos((string)$fstab, ' /sys/fs/cgroup ') === false) {
                $mountLine = "\ncgroup  /sys/fs/cgroup  cgroup  defaults  0   0\n";
                if (@file_put_contents('/etc/fstab', $mountLine, FILE_APPEND) === false) {
                    $log('[WARN] Unable to append cgroup mount to /etc/fstab');
                } else {
                    $log('Appended cgroup mount configuration to /etc/fstab (v1)');
                }
                runStep('Mounting /sys/fs/cgroup (v1)', 'mount /sys/fs/cgroup');
            } else {
                $log('[SKIP] cgroup v1 mount already present in /etc/fstab');
            }
        } elseif ($mode === 'v2') {
            $log('[SKIP] cgroup v2 detected; no fstab mount or cgroup-bin needed');
        } else {
            $log('[WARN] cgroup mode unknown; leaving mounts untouched');
        }

        // On v2, prefer TasksMax limits via slice; on v1, pids.max may be available.
        if ($mode === 'v1') {
            $rootPidSlice = '/sys/fs/cgroup/pids/user.slice/user-0.slice/pids.max';
            if (file_exists($rootPidSlice)) {
                runStep('Raising PID limit for root user slice (v1)', "sh -c 'echo 100000 > {$rootPidSlice}'");
            } else {
                $log('[SKIP] pids.max controller path missing (v1), relying on system defaults');
            }
        } else {
            $log('[SKIP] Using systemd TasksMax for PID limits (cgroup v2)');
        }

        // Advisory: hidepid=2 on /proc breaks rootless Docker under cgroup v2 on many hosts.
        // Emit a warning so operators can adjust policy if needed.
        try {
            $procMount = '';
            $hidepid   = '';
            $mi = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
            if (is_array($mi)) {
                foreach ($mi as $l) {
                    // Identify the /proc mountpoint line
                    if (preg_match('#\s(/proc)\s#', $l)) { $procMount = $l; }
                }
            }
            if ($procMount !== '') {
                // Extract mount options field (4th field) and parse hidepid option
                $parts = explode(' ', $procMount);
                if (isset($parts[3])) {
                    $opts = explode(',', $parts[3]);
                    foreach ($opts as $o) {
                        if (strpos($o, 'hidepid=') === 0) { $hidepid = substr($o, 8); break; }
                    }
                }
            }
            $rootlessTool = is_file('/usr/bin/dockerd-rootless-setuptool.sh');
            if ($mode === 'v2' && $hidepid !== '' && (int)$hidepid > 0 && $rootlessTool) {
                $log('[WARN] cgroup v2 with /proc hidepid>0 detected; rootless Docker may fail. Consider remounting /proc without hidepid or adjusting policy.');
            }
        } catch (\Throwable $e) {
            // Best-effort advisory only
        }
    }
}

if (!function_exists('pmssEnsureSystemdSlices')) {
    /**
     * Install tuned systemd slice overrides when missing.
     */
    function pmssEnsureSystemdSlices(?callable $logger = null): void
    {
        $log = pmssSelectLogger($logger);
        // Avoid touching the host systemd manager in test mode so dev tests stay hermetic.
        $skipSystemctl = (defined('PMSS_TEST_MODE') && PMSS_TEST_MODE === true);

        $reloadSystemd = static function (string $runMessage, string $skipMessage) use ($skipSystemctl): void {
            if ($skipSystemctl) {
                pmssLogStatus('SKIP', $skipMessage, 0);
                return;
            }
            runStep($runMessage, 'systemctl daemon-reload');
        };

        // Drop-in management must target /etc paths only; avoid vendor dirs.
        // Some legacy hosts still carry a vendor drop-in at:
        //   /usr/lib/systemd/system/user-.slice.d/99-pmss.conf
        //   /lib/systemd/system/user-.slice.d/99-pmss.conf
        // which can override PMSS settings (including TasksMax) and can even
        // reappear after dpkg updates. Remove it when found.
        $sawLegacyVendorDropin = false;
        if (!$skipSystemctl) {
            $legacyVendorDropins = [
                '/usr/lib/systemd/system/user-.slice.d/99-pmss.conf',
                '/lib/systemd/system/user-.slice.d/99-pmss.conf',
            ];
            foreach ($legacyVendorDropins as $legacyPath) {
                if (!is_file($legacyPath)) {
                    continue;
                }
                $sawLegacyVendorDropin = true;
                if (@unlink($legacyPath)) {
                    $log('[WARN] Removed legacy vendor systemd drop-in '.$legacyPath);
                } else {
                    $log('[WARN] Unable to remove legacy vendor systemd drop-in '.$legacyPath);
                }
            }
        }

        $mode = pmssCgroupMode();
        $dropDir = pmssResolvePathFromEnv('PMSS_SYSTEMD_USER_SLICE_DIR', '/etc/systemd/system/user-.slice.d');
        $target  = $dropDir.'/15-pmss.conf';
        if (!is_dir($dropDir)) {
            runStep('Creating user-.slice drop-in directory', 'install -d -m 0755 '.escapeshellarg($dropDir));
        }

        // Render template based on cgroup mode
        $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
        $tpl = $mode === 'v2'
            ? $cfgDir.'/template.cgroup.user-slice.v2.conf'
            : $cfgDir.'/template.cgroup.user-slice.v1.conf';
        if (!file_exists($tpl)) {
            $log('[WARN] Slice template missing: '.$tpl);
            return;
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
        $calcMax      = isset($policy['memoryMaxMiB']) && is_numeric($policy['memoryMaxMiB']) ? (int)$policy['memoryMaxMiB'] : (int)floor($policyHigh * 1.5);
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
        $raw = (string)@file_get_contents($tpl);
        foreach ($repl as $k => $v) { $raw = str_replace($k, $v, $raw); }
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
            return;
        }
        @chmod($tmpTarget, 0644);
        if (!@rename($tmpTarget, $target)) {
             $log('[WARN] Failed to atomically replace user-.slice drop-in '.$target);
             unlink($tmpTarget);
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
            $needsWrite = $existing === false || trim((string) $existing) !== trim($shadow);
            if ($needsWrite) {
                if (@file_put_contents($shadowPath, $shadow) !== false) {
                    @chmod($shadowPath, 0644);
                    $log('Installed '.$shadowPath.' TasksMax override (legacy shadow)');
                } else {
                    $log('[WARN] Failed to write '.$shadowPath.' TasksMax override (legacy shadow)');
                }
            }
        }

        $reloadSystemd(
            'Reloading systemd manager configuration',
            'Reloading systemd manager configuration (test mode)'
        );
        $log(sprintf('Installed %s slice override (mode=%s)', $target, $mode));

        // Ensure root (uid 0) slice is not limited: create user-0 specific override setting infinity/large limits.
        $rootDir = dirname($dropDir).'/user-0.slice.d';
        if (!is_dir($rootDir)) {
            @mkdir($rootDir, 0755, true);
        }
        // Use a suffix that sorts after legacy 99-pmss.conf drop-ins so root
        // remains unlimited even when a stale vendor file exists.
        $rootDrop = $rootDir.'/99-zz-pmss-unlimited.conf';
        $rootConf = "[Slice]\nMemoryHigh=infinity\nMemoryMax=infinity\nTasksMax=infinity\n";
        @file_put_contents($rootDrop, $rootConf);
        @chmod($rootDrop, 0644);
        $legacyRootDrop = $rootDir.'/99-pmss-unlimited.conf';
        if (is_file($legacyRootDrop)) {
            @unlink($legacyRootDrop);
        }
        $reloadSystemd(
            'Reloading systemd manager configuration (root slice)',
            'Reloading systemd manager configuration (root slice, test mode)'
        );

        // Apply updated TasksMax limits to already-running slices without
        // persisting per-user overrides. systemctl daemon-reload does not
        // retroactively reconfigure active slice cgroups, so older hosts may
        // remain stuck on legacy defaults (e.g. 250/512) until reboot.
        if (!$skipSystemctl) {
            runStep(
                'Unlimiting root user slice runtime TasksMax',
                "systemctl set-property --runtime 'user-0.slice' MemoryHigh=infinity MemoryMax=infinity TasksMax=infinity"
            );

            $rc = runCommand("systemctl list-units --type=slice 'user-*.slice' --state=active --no-legend --no-pager", false, $log);
            $stdout = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '';
            if ($rc === 0 && is_string($stdout) && trim($stdout) !== '') {
                foreach (preg_split('/\r?\n/', trim($stdout)) as $line) {
                    $line = trim((string) $line);
                    if ($line === '') {
                        continue;
                    }
                    $parts = preg_split('/\s+/', $line);
                    if (!is_array($parts) || count($parts) === 0) {
                        continue;
                    }
                    $unit = (string) $parts[0];
                    if ($unit === 'user-0.slice' || preg_match('/^user-\d+\.slice$/', $unit) !== 1) {
                        continue;
                    }

                    $showRc = runCommand('systemctl show '.escapeshellarg($unit).' -p TasksMax', false, $log);
                    $tasksLine = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '';
                    $tasksLine = trim(is_string($tasksLine) ? $tasksLine : '');
                    if ($showRc !== 0 || $tasksLine === '' || strpos($tasksLine, 'TasksMax=') !== 0) {
                        continue;
                    }
                    $current = trim(substr($tasksLine, strlen('TasksMax=')));
                    if ($current === '' || strtolower($current) === 'infinity' || !ctype_digit($current)) {
                        continue;
                    }
                    $currentInt = (int) $current;
                    if ($currentInt > 0 && $currentInt < 2048 && $tasksMax > $currentInt) {
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
            }
        }
    }
}

if (!function_exists('pmssEnsureLocaleBaseline')) {
    /**
     * Make sure essential locale assets exist before other services start.
     */
    function pmssEnsureLocaleBaseline(): void
    {
        $langLocale = 'en_US.UTF-8';
        $timeLocale = 'en_US.UTF-8';

        // Deduplicate locales to avoid redundant file reads and locale-gen calls.
        foreach (array_unique([$langLocale, $timeLocale]) as $locale) {
            $enabled = false;
            $gen = @file_get_contents('/etc/locale.gen');
            if (is_string($gen)) {
                foreach (preg_split('/\r?\n/', $gen) as $line) {
                    $trim = trim($line);
                    if ($trim === '') {
                        continue;
                    }
                    if ($trim[0] === '#') {
                        continue;
                    }
                    if (stripos($trim, $locale.' UTF-8') === 0) {
                        $enabled = true;
                        break;
                    }
                }
            }
            if (!$enabled) {
                $line = $locale.' UTF-8';
                if ($gen === false) {
                    // Best effort: create file with the required locale line
                    @file_put_contents('/etc/locale.gen', $line."\n");
                    logMessage('[WARN] /etc/locale.gen missing; created with '.$line);
                } else {
                    if (strpos($gen, $line) === false) {
                        // Append the desired locale line if not present at all
                        if (@file_put_contents('/etc/locale.gen', rtrim($gen, "\r\n")."\n".$line."\n") === false) {
                            logMessage('[WARN] Unable to append '.$line.' to /etc/locale.gen');
                        } else {
                            logMessage('Appended '.$line.' to /etc/locale.gen');
                        }
                    } else {
                        // Un-comment the existing line if commented out
                        runStep('Enabling '.$locale.' in /etc/locale.gen',
                            "sed -i 's/^# *".$locale." UTF-8/".$locale." UTF-8/' /etc/locale.gen");
                    }
                }
            }

            $out = [];
            @exec('locale -a 2>/dev/null', $out);
            $has = false;
            if (!empty($out)) {
                $needle1 = strtolower($locale);
                $needle2 = strtolower(str_replace('UTF-8', 'utf8', $locale));
                foreach ($out as $line) {
                    $val = strtolower(trim((string) $line));
                    if ($val === $needle1 || $val === $needle2) {
                        $has = true;
                        break;
                    }
                }
            }
            if (!$has || !$enabled) {
                runStep('Generating '.$locale.' locale', 'locale-gen '.$locale);
            } else {
                logMessage('[SKIP] '.$locale.' already generated');
            }
        }

        $defaultMatches = false;
        $data = @file_get_contents('/etc/default/locale');
        if (is_string($data)) {
            $lang = null;
            $lcTime = null;
            foreach (preg_split('/\r?\n/', $data) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (stripos($line, 'LANG=') === 0) {
                    $lang = trim(substr($line, 5));
                }
                if (stripos($line, 'LC_TIME=') === 0) {
                    $lcTime = trim(substr($line, 8));
                }
            }
            $defaultMatches = ($lang === $langLocale && $lcTime === $timeLocale);
        }
        if (!$defaultMatches) {
            runStep(
                'Setting default system locale',
                'update-locale LANG='.$langLocale.' LC_TIME='.$timeLocale
            );
        } else {
            logMessage('[SKIP] Default system locale already set to '.$langLocale.' (LC_TIME='.$timeLocale.')');
        }

        // Ensure system timezone matches the Finland/Helsinki baseline.
        $tz = trim((string) @file_get_contents('/etc/timezone'));
        if ($tz !== 'Europe/Helsinki') {
            runStep(
                'Setting system timezone to Europe/Helsinki',
                "timedatectl set-timezone Europe/Helsinki 2>/dev/null || (ln -sf /usr/share/zoneinfo/Europe/Helsinki /etc/localtime && echo 'Europe/Helsinki' > /etc/timezone)"
            );
        } else {
            logMessage('[SKIP] System timezone already set to Europe/Helsinki');
        }

        require_once __DIR__.'/../motd/Generator.php';
        \Motd::motdGenerate();
    }
}


if (!function_exists('pmssEnsureLegacySysctlBaseline')) {
    /**
     * Recreate the legacy BFQ/sysctl configuration shipped with PMSS.
     */
    function pmssEnsureLegacySysctlBaseline(?callable $logger = null): void
    {
        $log    = pmssSelectLogger($logger);
        $target = '/etc/sysctl.d/1-pmss-defaults.conf';

        $lines = ['# Pulsed Media Config'];

        // Dynamically detect sd* devices for scheduling/readahead tuning
        $disks = glob('/sys/block/sd*');
        if ($disks) {
            foreach ($disks as $path) {
                $dev = basename($path);
                // Only tune if not a partition (glob matches block devices, so sda is fine, sda1 isn't in /sys/block)
                if (preg_match('/^sd[a-z]+$/', $dev)) {
                    $lines[] = "block/{$dev}/queue/scheduler = bfq";
                    $lines[] = "block/{$dev}/queue/read_ahead_kb = 1024";
                }
            }
        }

        // Network and Security Hardening
        $lines[] = '';
        $lines[] = 'net.ipv4.ip_forward = 1';
        $lines[] = 'fs.protected_regular = 2';
        $lines[] = 'fs.protected_fifos = 2';
        $lines[] = 'kernel.yama.ptrace_scope = 1';

        $content = implode("\n", $lines);

        // Check if file needs updating
        $existing = @file_get_contents($target);
        if ($existing !== false && trim($existing) === trim($content)) {
            $log('[SKIP] Legacy sysctl defaults already present and up to date');
            return;
        }

        if (!is_dir(dirname($target))) {
            @mkdir(dirname($target), 0755, true);
        }
        @file_put_contents($target, $content.PHP_EOL);
        runStep('Reloading sysctl configuration', 'sysctl --system');
        $log('Refreshed legacy sysctl defaults at '.$target);
    }
}

if (!function_exists('pmssConfigureRootShellDefaults')) {
    /**
     * Ensure root shell defaults mirror the historical installer behaviour.
     */
    function pmssConfigureRootShellDefaults(?callable $logger = null): void
    {
        $log    = pmssSelectLogger($logger);
        $bashrc = '/root/.bashrc';
        $lines  = file_exists($bashrc) ? file($bashrc, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) {
            $lines = [];
        }

        $updates = [];
        $alias   = "alias ls='ls --color=auto'";
        $pathAdd = 'PATH=$PATH:/scripts';

        if (!in_array($alias, $lines, true)) {
            $lines[]   = $alias;
            $updates[] = $alias;
        }
        if (!in_array($pathAdd, $lines, true)) {
            $lines[]   = $pathAdd;
            $updates[] = $pathAdd;
        }

        if ($updates === []) {
            $log('[SKIP] Root shell defaults already configured');
            return;
        }

        @file_put_contents($bashrc, implode(PHP_EOL, $lines).PHP_EOL);
        $log('Appended root shell defaults: '.implode(', ', $updates));
    }
}

