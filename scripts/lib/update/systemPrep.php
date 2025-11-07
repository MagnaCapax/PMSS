<?php
/**
 * Base system preparation helpers executed during update-step2.
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';

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
        if (is_file('/sys/fs/cgroup/cgroup.controllers')) {
            return 'v2';
        }
        // Basic v1 hint: presence of controller directories under /sys/fs/cgroup/
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
    }
}

if (!function_exists('pmssEnsureSystemdSlices')) {
    /**
     * Install tuned systemd slice overrides when missing.
     */
    function pmssEnsureSystemdSlices(?callable $logger = null): void
    {
        $log = pmssSelectLogger($logger);

        // Clean up obsolete vendor drop-ins
        foreach ([
            '/usr/lib/systemd/user-.slice.d/99-pmss.conf',
            '/usr/lib/systemd/system/user-.slice.d/15-pmss.conf',
        ] as $obsolete) {
            if (file_exists($obsolete)) {
                @unlink($obsolete);
                $log('Removed obsolete vendor drop-in '.$obsolete);
            }
        }

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
        $tasksMax     = isset($policy['tasksMax']) && is_numeric($policy['tasksMax']) ? (int)$policy['tasksMax'] : 4096;

        $repl = [
            '%%USER_MEMORY_HIGH%%' => (string)$policyHigh,
            '%%USER_MEMORY_MAX%%'  => (string)$calcMax,
            '%%USER_CPUWEIGHT%%'   => (string)$cpuWeight,
            '%%USER_IOWEIGHT%%'    => (string)$ioWeight,
            '%%TASKS_MAX%%'        => (string)$tasksMax,
        ];
        $raw = (string)@file_get_contents($tpl);
        foreach ($repl as $k => $v) { $raw = str_replace($k, $v, $raw); }
        if (@file_put_contents($target, $raw) === false) {
            $log('[WARN] Failed to write user-.slice drop-in '.$target);
            return;
        }
        runStep('Setting permissions on user slice override', 'chmod 644 '.escapeshellarg($target));
        runStep('Reloading systemd manager configuration', 'systemctl daemon-reload');
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
        runStep('Reloading systemd manager configuration (root slice)', 'systemctl daemon-reload');
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
        runStep('Generating en_US.UTF-8 locale', 'locale-gen en_US.UTF-8');
        runStep('Setting default system locale', 'update-locale LANG=en_US.UTF-8 LC_ALL=en_US.UTF-8');
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
        runStep('Ensuring en_US.UTF-8 locale is enabled', "sed -i 's/# en_US.UTF-8 UTF-8/en_US.UTF-8 UTF-8/g' /etc/locale.gen");
        runStep('Regenerating locales', 'locale-gen');
        runStep('Setting default LANG in /etc/default/locale', "sed -i 's/LANG=en_US\\n/LANG=en_US.UTF-8/g' /etc/default/locale");
    }
}

if (!function_exists('pmssEnsureLegacySysctlBaseline')) {
    /**
     * Recreate the legacy BFQ/sysctl configuration shipped with PMSS.
     */
    function pmssEnsureLegacySysctlBaseline(?callable $logger = null): void
    {
        $log     = pmssSelectLogger($logger);
        $target  = '/etc/sysctl.d/1-pmss-defaults.conf';
        $content = <<<CONF
# Pulsed Media Config
block/sda/queue/scheduler = bfq
block/sdb/queue/scheduler = bfq
block/sdc/queue/scheduler = bfq
block/sdd/queue/scheduler = bfq
block/sde/queue/scheduler = bfq
block/sdf/queue/scheduler = bfq

block/sda/queue/read_ahead_kb = 1024
block/sdb/queue/read_ahead_kb = 1024
block/sdc/queue/read_ahead_kb = 1024
block/sdd/queue/read_ahead_kb = 1024
block/sde/queue/read_ahead_kb = 1024
block/sdf/queue/read_ahead_kb = 1024

net.ipv4.ip_forward = 1
CONF;

        $existing = @file_get_contents($target);
        if ($existing !== false && trim($existing) === trim($content)) {
            $log('[SKIP] Legacy sysctl defaults already present');
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
