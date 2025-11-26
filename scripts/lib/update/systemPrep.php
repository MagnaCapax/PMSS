<?php
/**
 * Base system preparation helpers executed during update-step2.
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';

if (!function_exists('pmssHasLocale')) {
    /**
     * Return true when the given locale (e.g., en_US.UTF-8) is generated.
     */
    function pmssHasLocale(string $locale): bool
    {
        $out = [];
        @exec('locale -a 2>/dev/null', $out);
        if (empty($out)) {
            return false;
        }
        $needle1 = strtolower($locale);
        $needle2 = strtolower(str_replace('UTF-8', 'utf8', $locale));
        foreach ($out as $line) {
            $val = strtolower(trim($line));
            if ($val === $needle1 || $val === $needle2) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('pmssLocaleEnabledInGen')) {
    /**
     * True when /etc/locale.gen has an uncommented line for the locale.
     */
    function pmssLocaleEnabledInGen(string $locale): bool
    {
        $data = @file_get_contents('/etc/locale.gen');
        if ($data === false) {
            return false;
        }
        foreach (preg_split('/\r?\n/', $data) as $line) {
            $trim = trim($line);
            if ($trim === '') continue;
            if ($trim[0] === '#') continue;
            if (stripos($trim, $locale.' UTF-8') === 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('pmssDefaultLocaleMatches')) {
    /**
     * Check if /etc/default/locale sets LANG/LC_ALL to the target.
     */
    function pmssDefaultLocaleMatches(string $target): bool
    {
        $data = @file_get_contents('/etc/default/locale');
        if ($data === false) {
            return false;
        }
        $lang = null; $lcAll = null;
        foreach (preg_split('/\r?\n/', $data) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (stripos($line, 'LANG=') === 0)   { $lang  = trim(substr($line, 5)); }
            if (stripos($line, 'LC_ALL=') === 0) { $lcAll = trim(substr($line, 7)); }
        }
        return ($lang === $target && $lcAll === $target);
    }
}

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

        $mode = pmssCgroupMode();
        $dropDir = getenv('PMSS_SYSTEMD_USER_SLICE_DIR');
        if (!is_string($dropDir) || $dropDir === '') {
            $dropDir = '/etc/systemd/system/user-.slice.d';
        }
        $target  = $dropDir.'/15-pmss.conf';
        if (!is_dir($dropDir)) {
            runStep('Creating user-.slice drop-in directory', 'install -d -m 0755 '.escapeshellarg($dropDir));
        }

        // Render template based on cgroup mode
        $cfgDir = getenv('PMSS_CONFIG_DIR');
        if (!is_string($cfgDir) || $cfgDir === '') {
            $cfgDir = '/etc/seedbox/config';
        }
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
        $policyFile = rtrim($cfgDir,'/').'/cgroup.policy.php';
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
        $tasksMax     = isset($policy['tasksMax']) && is_numeric($policy['tasksMax']) ? (int)$policy['tasksMax'] : 512;
        
        // Calculate default CPUQuota: 85% of total logical cores (threads).
        // Fallback to 600% (6 cores) if detection fails.
        $cpuThreads   = pmssTotalCpuThreads();
        $defaultQuota = ($cpuThreads > 0) ? ($cpuThreads * 85) : 600;
        
        $cpuQuotaPct  = isset($policy['cpuQuotaPercent']) && is_numeric($policy['cpuQuotaPercent']) ? (int)$policy['cpuQuotaPercent'] : $defaultQuota;

        $repl = [
            '%%USER_CGROUP_MEMORY_HIGH%%' => (string)$policyHigh,
            '%%USER_CGROUP_MEMORY_MAX%%'  => (string)$calcMax,
            '%%USER_CGROUP_CPU_WEIGHT%%'  => (string)$cpuWeight,
            '%%USER_CGROUP_IO_WEIGHT%%'   => (string)$ioWeight,
            '%%USER_CGROUP_TASKS_MAX%%'   => (string)$tasksMax,
            '%%USER_CGROUP_CPU_QUOTA%%'   => (string)$cpuQuotaPct.'%',
        ];
        $raw = (string)@file_get_contents($tpl);
        foreach ($repl as $k => $v) { $raw = str_replace($k, $v, $raw); }
        // Append per-mount device throttles and weights from policy
        if (isset($policy['mounts']) && is_array($policy['mounts'])) {
            $append = [];
            foreach ($policy['mounts'] as $mount => $def) {
                if (!is_array($def)) continue;
                $src = trim((string)@shell_exec('findmnt -no SOURCE '.escapeshellarg($mount).' 2>/dev/null'));
                if ($src === '') continue;
                if (isset($def['ioWeight']) && is_numeric($def['ioWeight'])) {
                    $append[] = 'IODeviceWeight='.$src.' '.(int)$def['ioWeight'];
                }
                if (isset($def['readBw']))  { $append[] = 'IOReadBandwidthMax='.$src.' '.$def['readBw']; }
                if (isset($def['writeBw'])) { $append[] = 'IOWriteBandwidthMax='.$src.' '.$def['writeBw']; }
            }
            if (!empty($append)) { $raw .= "\n".implode("\n", $append)."\n"; }
        }
        if (@file_put_contents($target, $raw) === false) {
            $log('[WARN] Failed to write user-.slice drop-in '.$target);
            return;
        }
        runStep('Setting permissions on user slice override', 'chmod 644 '.escapeshellarg($target));
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
        $rootDrop = $rootDir.'/99-pmss-unlimited.conf';
        $rootConf = "[Slice]\nMemoryHigh=infinity\nMemoryMax=infinity\nTasksMax=infinity\n";
        @file_put_contents($rootDrop, $rootConf);
        @chmod($rootDrop, 0644);
        $reloadSystemd(
            'Reloading systemd manager configuration (root slice)',
            'Reloading systemd manager configuration (root slice, test mode)'
        );
    }
}

if (!function_exists('pmssResetCorePermissions')) {
    /**
     * Normalise permissions on key configuration directories.
     */
    function pmssResetCorePermissions(): void
    {
        runStep('Resetting /etc/seedbox permissions', 'chmod -R 755 /etc/seedbox');
        runStep('Resetting /scripts permissions', 'chmod -R 750 /scripts');
    }
}

if (!function_exists('pmssEnsureLocaleBaseline')) {
    /**
     * Make sure essential locale assets exist before other services start.
     */
    function pmssEnsureLocaleBaseline(): void
    {
        $locale = 'en_US.UTF-8';
        $enabled = pmssLocaleEnabledInGen($locale);
        if (!$enabled) {
            runStep('Enabling '.$locale.' in /etc/locale.gen', "sed -i 's/# \?".$locale." UTF-8/".$locale." UTF-8/g' /etc/locale.gen");
        }

        $has = pmssHasLocale($locale);
        if (!$has || !$enabled) {
            runStep('Generating '.$locale.' locale', 'locale-gen '.$locale);
        } else {
            logMessage('[SKIP] '.$locale.' already generated');
        }

        if (!pmssDefaultLocaleMatches($locale)) {
            runStep('Setting default system locale', 'update-locale LANG='.$locale.' LC_ALL='.$locale);
        } else {
            logMessage('[SKIP] Default system locale already set to '.$locale);
        }

        require_once __DIR__.'/../motd/Generator.php';
        \Motd::motdGenerate();
    }
}

if (!function_exists('pmssReapplyLocaleDefinitions')) {
    /**
     * Reapply locale configuration to catch legacy installations.
     */
    function pmssReapplyLocaleDefinitions(): void
    {
        // Reuse the same idempotent logic as the baseline to avoid repeated work.
        pmssEnsureLocaleBaseline();
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

if (!function_exists('pmssProtectHomePermissions')) {
    /**
     * Match the historical chmod applied by install.sh to /home.
     */
    function pmssProtectHomePermissions(): void
    {
        runStep('Restricting world access to /home', 'chmod o-rw /home');
    }
}
