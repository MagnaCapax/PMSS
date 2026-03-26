<?php
/**
 * Base system preparation helpers executed during update-step2.
 *
 * This remains the historical include path for system preparation while also
 * carrying the small baseline helpers that are only consumed through this
 * surface.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/runtime/processes.php';
require_once __DIR__.'/../runtime.php';

/** Read a non-negative integer override from the environment. */
function pmssSystemPrepReadDigitEnv(string $key): ?int
{
    return (($override = getenv($key)) !== false && ctype_digit($override)) ? (int) $override : null;
}

/**
 * Return total system memory in MiB (rounded).
 */
function pmssTotalMemMiB(): int
{
    if (($override = pmssSystemPrepReadDigitEnv('PMSS_TOTAL_MEM_MIB')) !== null) {
        return (int) $override;
    }

    return preg_match('/^MemTotal:\s+([0-9]+)/m', (string) @file_get_contents('/proc/meminfo'), $matches) === 1
        ? (int) round(((int) $matches[1]) / 1024)
        : 0;
}

/**
 * Return total logical CPU threads.
 */
function pmssTotalCpuThreads(): int
{
    if (($override = pmssSystemPrepReadDigitEnv('PMSS_TOTAL_CPU_THREADS')) !== null) {
        return (int) $override;
    }

    // Robust check using /proc/cpuinfo
    $count = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor');
    // Fallback to nproc if available
    return $count > 0 ? $count : max(0, (int) trim((string) @shell_exec('nproc')));
}

/**
 * Detect cgroup mode: 'v2', 'v1', or 'unknown'.
 */
function pmssCgroupMode(): string
{
    if (($override = getenv('PMSS_CGROUP_MODE')) === 'v1' || $override === 'v2') {
        return $override;
    }

    // Strongest signal: cgroup2 mount present in /proc/self/mountinfo.
    // Also treat cgroup.controllers as a definitive v2 signal.
    if (is_file('/sys/fs/cgroup/cgroup.controllers')
        || strpos((string) @file_get_contents('/proc/self/mountinfo'), ' - cgroup2 ') !== false) {
        return 'v2';
    }

    // Kernel cmdline override used by systemd to force v1 on newer Debian
    if (strpos((string) @file_get_contents('/proc/cmdline'), 'systemd.unified_cgroup_hierarchy=0') !== false) {
        return 'v1';
    }

    // v1 hint: presence of controller directories under /sys/fs/cgroup/
    foreach (glob('/sys/fs/cgroup/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (basename($dir) !== 'unified') {
            return 'v1';
        }
    }

    return 'unknown';
}

/**
 * Guarantee that cgroup mounts and PID limits are configured sanely.
 */
function pmssEnsureCgroupsConfigured(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    $mode = pmssCgroupMode();
    if ($mode === 'v1') {
        $fstab = @file_get_contents('/etc/fstab');
        if ($fstab === false || strpos($fstab, ' /sys/fs/cgroup ') === false) {
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
        if (file_exists('/sys/fs/cgroup/pids/user.slice/user-0.slice/pids.max')) {
            runStep('Raising PID limit for root user slice (v1)', "sh -c 'echo 100000 > /sys/fs/cgroup/pids/user.slice/user-0.slice/pids.max'");
        } else {
            $log('[SKIP] pids.max controller path missing (v1), relying on system defaults');
        }
    } else {
        $log('[SKIP] Using systemd TasksMax for PID limits (cgroup v2)');
    }

    // Advisory: hidepid=2 on /proc breaks rootless Docker under cgroup v2 on many hosts.
    // Emit a warning so operators can adjust policy if needed.
    try {
        $procMount = $hidepid = '';
        $mi = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
        if (is_array($mi)) {
            foreach ($mi as $l) {
                // Identify the /proc mountpoint line
                if (preg_match('#\s(/proc)\s#', $l)) {
                    $procMount = $l;
                }
            }
        }
        if ($procMount !== '') {
            // Extract mount options field (4th field) and parse hidepid option
            $parts = explode(' ', $procMount);
            if (isset($parts[3])) {
                foreach (explode(',', $parts[3]) as $o) {
                    if (strpos($o, 'hidepid=') === 0) {
                        $hidepid = substr($o, 8);
                        break;
                    }
                }
            }
        }
        if ($mode === 'v2' && $hidepid !== '' && (int) $hidepid > 0 && is_file('/usr/bin/dockerd-rootless-setuptool.sh')) {
            $log('[WARN] cgroup v2 with /proc hidepid>0 detected; rootless Docker may fail. Consider remounting /proc without hidepid or adjusting policy.');
        }
    } catch (\Throwable $e) {
        // Best-effort advisory only
    }
}

require_once __DIR__.'/systemPrep/systemdSlicesEnsure.php';

/**
 * Recreate the legacy BFQ/sysctl configuration shipped with PMSS.
 */
function pmssEnsureLegacySysctlBaseline(?callable $logger = null, ?string $targetOverride = null, bool $reload = true, ?string $modulesLoadOverride = null): void
{
    $log             = $logger ?: 'logMessage';
    $target          = $targetOverride ?? '/etc/sysctl.d/99-pmss.conf';
    $modulesLoadPath = $modulesLoadOverride ?? '/etc/modules-load.d/pmss-bbr.conf';
    // Persist TCP BBR module loading across reboots.
    $modulesContent = "# PMSS: enable TCP BBR\ntcp_bbr\n";

    // /sys block tuning is handled by the boot-time tuning service; sysctl only covers /proc/sys.
    // Network and Security Hardening
    $content = <<<'SYSCTL'
# Pulsed Media Config

net.core.default_qdisc = fq
net.ipv4.tcp_congestion_control = bbr
net.ipv4.ip_forward = 1
fs.protected_regular = 2
fs.protected_fifos = 2
kernel.yama.ptrace_scope = 1
kernel.kptr_restrict = 1
SYSCTL;

    // Check if file needs updating
    $existing = @file_get_contents($target);
    $sysctlUpToDate = $existing !== false && trim($existing) === trim($content);
    if ($sysctlUpToDate) {
        $log('[SKIP] Legacy sysctl defaults already present and up to date');
    } else {
        @mkdir(dirname($target), 0755, true);
        @file_put_contents($target, $content.PHP_EOL);
    }

    $modulesExisting = @file_get_contents($modulesLoadPath);
    $modulesUpToDate = $modulesExisting !== false && trim($modulesExisting) === trim($modulesContent);
    if ($modulesUpToDate) {
        $log('[SKIP] TCP BBR modules-load configuration already present and up to date');
    } else {
        @mkdir(dirname($modulesLoadPath), 0755, true);
        if (@file_put_contents($modulesLoadPath, $modulesContent) === false) {
            $log('[WARN] Unable to write TCP BBR modules-load configuration at '.$modulesLoadPath);
        } else {
            $log('Refreshed TCP BBR modules-load configuration at '.$modulesLoadPath);
        }
    }

    if ($sysctlUpToDate) {
        return;
    }

    $reload ? runStep('Reloading sysctl configuration', 'sysctl --system') : $log('[SKIP] sysctl reload disabled');
    $log('Refreshed legacy sysctl defaults at '.$target);
}

/**
 * Keep /tmp disk-backed on Debian 13+ by masking the systemd tmpfs unit.
 */
function pmssConfigureTempDiskBackedMount(?callable $logger = null, ?int $distroVersion = null): void
{
    $log = $logger ?: 'logMessage';
    if ($distroVersion === null && function_exists('pmssDetectDistro')) {
        $detected = pmssDetectDistro();
        $distroVersion = isset($detected['version']) ? (int) $detected['version'] : 0;
    }

    if ((int) $distroVersion < 13) {
        $log('[SKIP] Leaving /tmp mount policy unchanged before Debian 13');
        return;
    }

    if (pmssCommandPath('systemctl') === '') {
        $log('[WARN] systemctl unavailable; unable to mask tmp.mount');
        return;
    }

    $rc = runStep('Masking systemd tmp.mount to keep /tmp disk-backed', 'systemctl mask tmp.mount');
    if ($rc !== 0) {
        $log('[WARN] Failed to mask tmp.mount; /tmp may stay tmpfs-backed until corrected');
        return;
    }

    $log('Masked tmp.mount to keep /tmp disk-backed');
}

/**
 * Install and enable the PMSS boot tuning script + systemd unit.
 */
function pmssEnsureBootTuning(?callable $logger = null, ?string $scriptTarget = null, ?string $serviceTarget = null): void
{
    $log = $logger ?: 'logMessage';
    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $scriptTemplate = $cfgDir.'/template.pmss-boot-tuning.sh';
    $serviceTemplate = $cfgDir.'/template.systemd.pmss-boot-tuning.service';

    foreach ([$scriptTemplate => 'Boot tuning script template', $serviceTemplate => 'Boot tuning service template'] as $template => $label) {
        if (!is_file($template)) {
            $log('[SKIP] '.$label.' missing: '.$template);
            return;
        }
    }

    $scriptTarget = $scriptTarget ?? '/usr/local/sbin/pmss-boot-tuning.sh';
    $serviceTarget = $serviceTarget ?? '/etc/systemd/system/pmss-boot-tuning.service';

    $scriptRaw = @file_get_contents($scriptTemplate);
    if ($scriptRaw === false) {
        $log('[WARN] Unable to read boot tuning script template: '.$scriptTemplate);
        return;
    }

    $serviceRaw = @file_get_contents($serviceTemplate);
    if ($serviceRaw === false) {
        $log('[WARN] Unable to read boot tuning service template: '.$serviceTemplate);
        return;
    }
    $serviceRaw = str_replace('%%PMSS_BOOT_TUNING_SCRIPT%%', $scriptTarget, $serviceRaw);

    // Write files only when content changes to keep the run idempotent.
    foreach ([
        [$scriptTarget, $scriptRaw, 0755, 'Boot tuning script'],
        [$serviceTarget, $serviceRaw, 0644, 'Boot tuning service'],
    ] as $targetSpec) {
        [$path, $content, $mode, $label] = $targetSpec;
        $existing = @file_get_contents($path);
        if ($existing !== false && $existing === $content) {
            $log('[SKIP] '.$label.' already present and up to date');
            continue;
        }

        $dir = dirname($path);
        if (!pmssDirEnsureExists($dir, 0755)) {
            $log('[WARN] Unable to create '.$label.' directory: '.$dir);
            continue;
        }

        $tmp = $path.'.tmp';
        if (@file_put_contents($tmp, $content) === false) {
            $log('[WARN] Unable to write '.$label.' at '.$tmp);
            continue;
        }

        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            $log('[WARN] Unable to install '.$label.' at '.$path);
            @unlink($tmp);
            continue;
        }

        $log('Installed '.$label.' at '.$path);
    }

    if (($skipReason = pmssSystemdActionSkipReason(null, true, true)) !== '') {
        pmssLogStatus('SKIP', 'Enabling PMSS boot tuning service ('.$skipReason.')');
        return;
    }

    runStep('Reloading systemd unit files (PMSS boot tuning)', 'systemctl daemon-reload || true');
    runStep('Enabling PMSS boot tuning service', 'systemctl enable pmss-boot-tuning.service || true');
    runStep('Starting PMSS boot tuning service', 'systemctl start pmss-boot-tuning.service || true');
}

/**
 * Ensure /proc hidepid=2 and legacy grub cmdline defaults stay applied.
 *
 * Extra grub cmdline options and explicit grub settings are optional so
 * callers can enable stricter post-upgrade boot diagnostics when needed.
 */
function pmssEnsureBootDefaults(
    ?callable $logger = null,
    ?string $fstabPath = null,
    ?string $grubPath = null,
    ?string $grubOption = null,
    ?array $extraGrubOptions = null,
    ?array $extraGrubSettings = null
): void
{
    $log = $logger ?: 'logMessage';
    $fstabPath = $fstabPath ?? '/etc/fstab';
    $grubPath = $grubPath ?? '/etc/default/grub';
    $grubOption = $grubOption ?? 'systemd.unified_cgroup_hierarchy=0';

    $requiredGrubOptions = [];
    $grubOption = trim((string) $grubOption);
    if ($grubOption !== '') {
        $requiredGrubOptions[] = $grubOption;
    }
    if (is_array($extraGrubOptions)) {
        foreach ($extraGrubOptions as $option) {
            $option = trim((string) $option);
            if ($option !== '') {
                $requiredGrubOptions[] = $option;
            }
        }
    }
    $requiredGrubOptions = array_values(array_unique($requiredGrubOptions));

    $requiredGrubSettings = [];
    if (is_array($extraGrubSettings)) {
        foreach ($extraGrubSettings as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || preg_match('/^[A-Z0-9_]+$/', $key) !== 1) {
                continue;
            }
            $requiredGrubSettings[$key] = trim((string) $value);
        }
    }

    $writeWithBackup = static function (string $path, array $contentLines, string $label) use ($log): void {
        $backup = $path.'.pmss-backup-'.date('YmdHis');
        if (!@copy($path, $backup)) {
            $log('[WARN] Unable to create '.$label.' backup at '.$backup);
        }
        if (@file_put_contents($path, implode(PHP_EOL, $contentLines).PHP_EOL) === false) {
            $log('[WARN] Failed writing updated '.$path);
        }
    };

    // /proc hidepid baseline.
    $fstabChanged = false;
    if (is_readable($fstabPath) && ($lines = file($fstabPath, FILE_IGNORE_NEW_LINES)) !== false) {
        // Locate existing /proc entry when present.
        $found = false;
        foreach ($lines as $idx => $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#') {
                continue;
            }
            $columns = preg_split('/\s+/', $trim);
            if (count($columns) < 4 || $columns[1] !== '/proc' || $columns[2] !== 'proc') {
                continue;
            }
            $found = true;
            // Normalize hidepid option to enforce the legacy value.
            $options = $columns[3] === '' || $columns[3] === '-' ? 'defaults' : $columns[3];
            $parts = array_values(array_filter(explode(',', $options), 'strlen'));
            $new = [];
            $hidepid = false;
            foreach ($parts as $opt) {
                if (strpos($opt, 'hidepid=') === 0) {
                    if (!$hidepid) {
                        $new[] = 'hidepid=2';
                        $hidepid = true;
                    }
                    continue;
                }
                $new[] = $opt;
            }
            if (!$hidepid) {
                $new[] = 'hidepid=2';
            }
            $updated = implode(',', $new);
            if ($updated !== $columns[3]) {
                $columns[3] = $updated;
                $lines[$idx] = implode("\t", $columns);
                $fstabChanged = true;
                $log('Updated /proc mount options in '.$fstabPath);
            }
            break;
        }
        if (!$found) {
            $lines[] = "proc\t/proc\tproc\tdefaults,hidepid=2\t0\t0";
            $fstabChanged = true;
            $log('Added /proc mount with hidepid=2 to '.$fstabPath);
        }
        // Write fstab only when content changed, with a backup.
        if ($fstabChanged) {
            $writeWithBackup($fstabPath, $lines, 'fstab');

            // Remount /proc after fstab changes so the runtime view matches.
            $command = pmssBuildCommand('mount', ['-o', 'remount,hidepid=2', '/proc']);
            runStep('Remounting /proc with hidepid=2', $command);
        }
    } else {
        $log('[WARN] '.$fstabPath.' not readable; skipping /proc hidepid enforcement');
    }

    // Grub cmdline baseline.
    $grubChanged = false;
    if (is_readable($grubPath) && ($lines = file($grubPath, FILE_IGNORE_NEW_LINES)) !== false) {
        // Update the first GRUB_CMDLINE entry or add a new one.
        $found = false;
        foreach ($lines as $idx => $line) {
            if (!preg_match('/^(GRUB_CMDLINE_LINUX_DEFAULT|GRUB_CMDLINE_LINUX)=("|\')([^"\']*)("|\')/', $line, $match)) {
                continue;
            }
            $found = true;
            $key = $match[1];
            $quote = $match[2];
            $value = $match[3];

            $currentOptions = preg_split('/\s+/', trim($value)) ?: [];
            $currentOptions = array_values(array_filter($currentOptions, 'strlen'));
            foreach ($requiredGrubOptions as $option) {
                if (!in_array($option, $currentOptions, true)) {
                    $currentOptions[] = $option;
                }
            }
            $updatedValue = implode(' ', $currentOptions);

            if ($updatedValue !== $value) {
                $lines[$idx] = $key.'='.$quote.$updatedValue.$quote;
                $grubChanged = true;
                $log('Updated '.$key.' options in '.$grubPath);
            }
            break;
        }
        if (!$found && !empty($requiredGrubOptions)) {
            $lines[] = 'GRUB_CMDLINE_LINUX_DEFAULT="'.implode(' ', $requiredGrubOptions).'"';
            $grubChanged = true;
            $log('Added GRUB_CMDLINE_LINUX_DEFAULT to '.$grubPath);
        }

        // Ensure additional grub key/value settings (e.g. serial console)
        // are present with explicit values.
        foreach ($requiredGrubSettings as $settingKey => $settingValue) {
            $settingFound = false;
            $settingPrefix = $settingKey.'=';
            foreach ($lines as $lineIdx => $line) {
                if (strpos($line, $settingPrefix) !== 0) {
                    continue;
                }
                $settingFound = true;
                $rawValue = trim(substr($line, strlen($settingPrefix)));
                if (strlen($rawValue) >= 2) {
                    $first = $rawValue[0];
                    $last = $rawValue[strlen($rawValue) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $rawValue = substr($rawValue, 1, -1);
                    }
                }
                if ($rawValue !== $settingValue) {
                    $lines[$lineIdx] = $settingKey.'="'.$settingValue.'"';
                    $grubChanged = true;
                    $log('Updated '.$settingKey.' in '.$grubPath);
                }
                break;
            }
            if (!$settingFound) {
                $lines[] = $settingKey.'="'.$settingValue.'"';
                $grubChanged = true;
                $log('Added '.$settingKey.' to '.$grubPath);
            }
        }

        // Persist grub updates only when modified, keeping a backup.
        if ($grubChanged) {
            $writeWithBackup($grubPath, $lines, 'grub');
        }
    } else {
        $log('[WARN] '.$grubPath.' not readable; skipping grub cmdline update');
    }
    // Apply grub changes when possible and warn about reboot.
    if ($grubChanged) {
        $hasUpdateGrub = pmssCommandPath('update-grub') !== '';
        if ($hasUpdateGrub) {
            runStep('Updating GRUB configuration', 'update-grub');
        } else {
            $log('[WARN] update-grub not available; run update-grub after editing '.$grubPath);
        }
        $cmdline = @file_get_contents('/proc/cmdline');
        if ($grubOption !== '' && $cmdline !== false && strpos($cmdline, $grubOption) === false) {
            $log('[WARN] '.$grubOption.' will apply after reboot');
        }
    }
}

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
                if ($trim === '' || $trim[0] === '#') {
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

    require_once dirname(__DIR__).'/../motd/Generator.php';
    \Motd::motdGenerate();
}

/**
 * Ensure root shell defaults mirror the historical installer behaviour.
 */
function pmssConfigureRootShellDefaults(?callable $logger = null): void
{
    $log    = $logger ?: 'logMessage';
    $bashrc = '/root/.bashrc';
    $lines = file_exists($bashrc) ? (file($bashrc, FILE_IGNORE_NEW_LINES) ?: []) : [];

    $defaults = [
        "alias ls='ls --color=auto'",
        'PATH=$PATH:/scripts',
    ];
    if (($missing = array_diff($defaults, $lines)) === []) {
        $log('[SKIP] Root shell defaults already configured');
        return;
    }

    @file_put_contents($bashrc, implode(PHP_EOL, array_merge($lines, $missing)).PHP_EOL);
    $log('Appended root shell defaults: '.implode(', ', $missing));
}
