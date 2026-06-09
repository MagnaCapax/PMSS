<?php
/**
 * Hardware-aware sysctl profile helpers for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__, 2).'/network/interface.php';

/** Read a boolean-like environment override when present. */
function pmssSystemPrepReadBoolEnv(string $key): ?bool
{
    $override = getenv($key);
    if ($override === false) return null;
    if (pmssValueMatchesNormalized($override, ['1', 'true', 'yes'])) return true;
    return pmssValueMatchesNormalized($override, ['0', 'false', 'no']) ? false : null;
}

/** Refresh one managed sysctl-adjacent file and report whether it changed. */
function pmssSysctlManagedContentRefresh(string $path, string $content, string $label, callable $log, int $mode = 0644): array
{
    $skipLog = '[SKIP] '.ucfirst($label).' already present and up to date';
    $warnLog = '[WARN] Unable to write '.$label.' at '.$path;
    if (($existing = @file_get_contents($path)) !== false && trim($existing) === trim($content)) {
        $log($skipLog);
        return [true, true];
    }

    if (!pmssDirEnsureExists(dirname($path), 0755)) {
        $log($warnLog);
        return [false, false];
    }

    return [
        false,
        pmssWriteManagedPathFile($path, $content, $label, $log, null, null, $mode, $warnLog),
    ];
}

/**
 * Recreate the PMSS-owned hardware-aware sysctl baseline.
 */
function pmssEnsureLegacySysctlBaseline(?callable $logger = null, ?string $targetOverride = null, bool $reload = true, ?string $modulesLoadOverride = null): void
{
    $log             = $logger ?: 'logMessage';
    $target          = $targetOverride ?? '/etc/sysctl.d/99-pmss.conf';
    $modulesLoadPath = $modulesLoadOverride ?? '/etc/modules-load.d/pmss-bbr.conf';
    $overridePath    = pmssResolvePathFromEnv('PMSS_SYSCTL_OVERRIDES_PATH', '/etc/sysctl.d/90-pmss-overrides.conf');
    // Persist TCP BBR module loading across reboots.
    $modulesContent = "# PMSS: enable TCP BBR\ntcp_bbr\n";

    // /sys block tuning is handled by the boot-time tuning service; sysctl only covers /proc/sys.
    $profile = pmssSysctlProfileDetect();
    $overrideKeys = pmssSysctlOverridesParse($overridePath);
    $groupedSettings = pmssSysctlSettingsFilterOverrides(pmssSysctlSettingsBuild($profile), $overrideKeys);
    $content = pmssSysctlConfigRender($groupedSettings);
    $existingSettings = pmssSysctlFileParse($target);
    $changes = pmssSysctlChangesDescribe($existingSettings, $groupedSettings);

    [$sysctlUpToDate, $sysctlWriteOk] = pmssSysctlManagedContentRefresh(
        $target,
        $content.PHP_EOL,
        'legacy sysctl defaults',
        $log
    );

    pmssSysctlSummaryWrite($logger, $profile, $groupedSettings, $overrideKeys, $changes);

    [$modulesUpToDate, $modulesWriteOk] = pmssSysctlManagedContentRefresh(
        $modulesLoadPath,
        $modulesContent,
        'TCP BBR modules-load configuration',
        $log
    );
    if (!$modulesUpToDate && $modulesWriteOk) {
        $log('Refreshed TCP BBR modules-load configuration at '.$modulesLoadPath);
    }

    if ($sysctlUpToDate || !$sysctlWriteOk) {
        return;
    }

    $reload ? runStep('Reloading sysctl configuration', 'sysctl --system') : $log('[SKIP] sysctl reload disabled');
    $log('Refreshed legacy sysctl defaults at '.$target);
}

/** Detect whether any swap device is configured. */
function pmssSysctlHasSwap(): bool
{
    if (($override = pmssSystemPrepReadBoolEnv('PMSS_SYSCTL_HAS_SWAP')) !== null) return $override;

    return count(pmssReadRegularFileNonEmptyLines('/proc/swaps')) > 1;
}

/** Return true when a block device or one of its slaves is non-rotational. */
function pmssSysctlBlockDeviceIsFast(string $deviceName, string $sysClassBlockRoot, array &$seen = []): bool
{
    $deviceName = basename($deviceName);
    if ($deviceName === '' || isset($seen[$deviceName])) {
        return false;
    }

    $seen[$deviceName] = true;
    $queuePath = rtrim($sysClassBlockRoot, '/').'/'.$deviceName.'/queue/rotational';
    if (is_file($queuePath)) {
        return (pmssReadRegularFileTrimmed($queuePath) ?? '') === '0';
    }

    foreach (glob(rtrim($sysClassBlockRoot, '/').'/'.$deviceName.'/slaves/*') ?: [] as $slavePath) {
        if (pmssSysctlBlockDeviceIsFast((string) basename($slavePath), $sysClassBlockRoot, $seen)) {
            return true;
        }
    }

    return false;
}

/** Detect whether swap lives on non-rotational storage. */
function pmssSysctlSwapIsFast(): bool
{
    if (($override = pmssSystemPrepReadBoolEnv('PMSS_SYSCTL_SWAP_IS_FAST')) !== null) return $override;

    if (!pmssSysctlHasSwap()) {
        return false;
    }

    $sysClassBlockRoot = pmssResolvePathFromEnv('PMSS_SYSCTL_SYS_CLASS_BLOCK_PATH', '/sys/class/block');
    foreach (pmssReadRegularFileNonEmptyLines('/proc/swaps') as $index => $line) {
        if ($index === 0) {
            continue;
        }

        $columns = preg_split('/\s+/', trim($line));
        if (!is_array($columns) || !isset($columns[0]) || $columns[0] === '') {
            continue;
        }

        $path = (string) $columns[0];
        $resolvedPath = realpath($path);
        $deviceName = basename($resolvedPath !== false ? $resolvedPath : $path);
        $seen = [];
        if (pmssSysctlBlockDeviceIsFast($deviceName, $sysClassBlockRoot, $seen)) {
            return true;
        }
    }

    return false;
}

/** Detect the default-route interface speed in Mbps. */
function pmssSysctlNicSpeedMbps(): int
{
    if (($override = pmssSystemPrepReadDigitEnv('PMSS_SYSCTL_NIC_SPEED_MBPS')) !== null) {
        return $override;
    }

    $routePath = pmssResolvePathFromEnv('PMSS_SYSCTL_PROC_NET_ROUTE_PATH', '/proc/net/route');
    $iface = '';
    foreach (pmssReadRegularFileNonEmptyLines($routePath) as $index => $line) {
        if ($index === 0) {
            continue;
        }

        $columns = preg_split('/\s+/', trim($line));
        if (is_array($columns) && isset($columns[0], $columns[1]) && $columns[1] === '00000000') {
            $iface = pmssNetworkInterfaceNameNormalize((string) $columns[0], 15);
            if ($iface === '') {
                continue;
            }
            break;
        }
    }

    if ($iface === '') {
        return 1000;
    }

    $speedPath = pmssResolvePathFromEnv('PMSS_SYSCTL_SYS_CLASS_NET_PATH', '/sys/class/net').'/'.$iface.'/speed';
    $speed = pmssReadRegularFileTrimmed($speedPath) ?? '';
    return ctype_digit($speed) ? (int) $speed : 1000;
}

/** Run one quiet probe command and return only its exit-status meaning. */
function pmssSysctlCommandQuietSucceeds(string $binaryPath, array $args = []): ?bool
{
    if ($binaryPath === '' || strpos($binaryPath, "\0") !== false || !is_executable($binaryPath) || !function_exists('exec')) {
        return null;
    }

    $status = 1;
    $output = [];
    @exec(pmssCommandArgvShellQuote(array_merge([$binaryPath], $args)).' >/dev/null 2>&1', $output, $status);
    return $status === 0;
}

/** Detect whether the current host is a virtual machine. */
function pmssSysctlIsVm(): bool
{
    if (($override = pmssSystemPrepReadBoolEnv('PMSS_SYSCTL_IS_VM')) !== null) return $override;

    if (($systemdDetectVirt = pmssCommandPath('systemd-detect-virt')) !== '') {
        $virtualized = pmssSysctlCommandQuietSucceeds($systemdDetectVirt, ['--quiet']);
        if ($virtualized !== null) {
            return $virtualized;
        }
    }

    foreach (['/sys/class/dmi/id/product_name', '/sys/class/dmi/id/sys_vendor'] as $path) {
        $value = strtolower(pmssReadRegularFileTrimmed($path) ?? '');
        if ($value !== '' && preg_match('/kvm|vmware|virtualbox|qemu|bochs|openstack|hvm domu|xen/', $value)) {
            return true;
        }
    }

    return false;
}

/** Detect whether conntrack sysctls are available on this host. */
function pmssSysctlHasConntrack(): bool
{
    if (($override = pmssSystemPrepReadBoolEnv('PMSS_SYSCTL_HAS_CONNTRACK')) !== null) return $override;

    $procSysRoot = pmssResolvePathFromEnv('PMSS_SYSCTL_PROC_SYS_PATH', '/proc/sys');
    return is_dir($procSysRoot.'/net/netfilter') || is_dir($procSysRoot.'/net/ipv4/netfilter');
}

/** Detect the current host profile used for sysctl tuning. */
function pmssSysctlProfileDetect(): array
{
    $totalMemMiB = max(0, pmssTotalMemMiB());
    $ramGb = max(1, (int) ceil($totalMemMiB / 1024));
    $nicSpeedMbps = max(0, pmssSysctlNicSpeedMbps());
    $hasSwap = pmssSysctlHasSwap();
    $swapIsFast = $hasSwap && pmssSysctlSwapIsFast();

    return [
        'ram_gb' => $ramGb,
        'total_mem_mib' => $totalMemMiB,
        'has_swap' => $hasSwap,
        'swap_is_fast' => $swapIsFast,
        'nic_speed_mbps' => $nicSpeedMbps,
        'nic_speed_gbps' => $nicSpeedMbps >= 10000 ? 10 : 1,
        'is_vm' => pmssSysctlIsVm(),
        'has_conntrack' => pmssSysctlHasConntrack(),
    ];
}

/** Build memory sysctl settings for the detected host profile. */
function pmssSysctlMemorySettingsBuild(array $profile): array
{
    $fastSwap = !empty($profile['swap_is_fast']);
    $hasSwap = !empty($profile['has_swap']);
    $isVm = !empty($profile['is_vm']);
    $ramGb = max(1, (int) ($profile['ram_gb'] ?? 1));

    $settings = [
        'vm.swappiness' => '10',
        'vm.vfs_cache_pressure' => '50',
        'vm.min_free_kbytes' => (string) min(2097152, max(131072, $ramGb * 5120)),
        'vm.dirty_ratio' => '20',
        'vm.dirty_background_ratio' => '5',
    ];

    if (!$hasSwap) $settings['vm.swappiness'] = '60';
    elseif ($isVm) $settings['vm.min_free_kbytes'] = '131072';
    elseif ($fastSwap) $settings = array_merge($settings, [
        'vm.swappiness' => '100',
        'vm.vfs_cache_pressure' => '2',
        'vm.min_free_kbytes' => (string) min(4194304, max(131072, $ramGb * 10240)),
        'vm.dirty_ratio' => '40',
        'vm.dirty_background_ratio' => '10',
    ]);

    return $settings + ['vm.dirty_expire_centisecs' => '1500', 'vm.dirty_writeback_centisecs' => '500'];
}

/** Build network sysctl settings for the detected host profile. */
function pmssSysctlNetworkSettingsBuild(array $profile): array
{
    $tenGigabit = (int) ($profile['nic_speed_gbps'] ?? 1) >= 10;
    $networkBufferBytes = $tenGigabit ? '67108864' : '16777216';
    $networkTcpBytes = $tenGigabit ? '134217728' : '67110000';
    return [
        'net.core.rmem_max' => $networkBufferBytes,
        'net.core.wmem_max' => $networkBufferBytes,
        'net.core.rmem_default' => $networkBufferBytes,
        'net.core.wmem_default' => $networkBufferBytes,
        'net.core.optmem_max' => $networkBufferBytes,
        'net.core.netdev_max_backlog' => $tenGigabit ? '524288' : '262144',
        'net.core.somaxconn' => $tenGigabit ? '4096' : '2000',
        'net.ipv4.tcp_rmem' => '4096 524000 '.$networkTcpBytes,
        'net.ipv4.tcp_wmem' => '4096 524000 '.$networkTcpBytes,
        'net.core.default_qdisc' => 'fq',
        'net.ipv4.tcp_congestion_control' => 'bbr',
        'net.ipv4.tcp_mtu_probing' => '1',
        'net.ipv4.tcp_keepalive_time' => '1200',
        'net.ipv4.tcp_keepalive_probes' => '9',
        'net.ipv4.tcp_keepalive_intvl' => '60',
        'net.ipv4.tcp_max_syn_backlog' => '4096',
        'net.ipv4.tcp_fin_timeout' => '60',
        'net.ipv4.tcp_max_tw_buckets' => '1440000',
        'net.ipv4.tcp_tw_reuse' => '1',
        'net.ipv4.ip_local_port_range' => '1024 65535',
        'net.ipv4.tcp_mem' => '3086631 4115510 6173262',
        'net.ipv4.ip_forward' => '1',
    ];
}

/** Build PMSS security sysctl settings. */
function pmssSysctlSecuritySettingsBuild(): array
{
    return [
        'kernel.pid_max' => '262144',
        'kernel.unprivileged_userns_clone' => '1',
        'fs.suid_dumpable' => '0',
        'fs.file-max' => '3000000',
        'fs.protected_regular' => '2',
        'fs.protected_fifos' => '2',
        // scope=2 (admin-only ptrace) blocks pidfd_getfd-via-mm=NULL exploit class
        // (Linus commit 31e62c2ebbfd, Qualys-reported 2026-05-14, ssh-keysign-pwn).
        // scope=1 allows ptrace of descendants - attacker forks the SUID target,
        // child IS descendant, scope=1 permits attach. scope=2 requires CAP_SYS_PTRACE.
        // Customer impact on PMSS: none verified (no debuggers installed by default,
        // no PMSS scripts use ptrace, no customer ptrace activity observed in fleet sample).
        'kernel.yama.ptrace_scope' => '2',
        'kernel.kptr_restrict' => '1',
        'net.ipv4.conf.all.rp_filter' => '1',
        'net.ipv4.conf.all.accept_source_route' => '0',
        'net.ipv4.conf.all.send_redirects' => '0',
        'net.ipv4.icmp_echo_ignore_broadcasts' => '1',
        'net.ipv4.icmp_ignore_bogus_error_responses' => '1',
        'net.ipv6.conf.all.disable_ipv6' => '1',
        'net.ipv6.conf.default.disable_ipv6' => '1',
    ];
}

/** Build conntrack settings only when the host exposes conntrack sysctls. */
function pmssSysctlConntrackSettingsBuild(array $profile): array
{
    if (empty($profile['has_conntrack'])) return [];

    $settings = [
        'net.netfilter.nf_conntrack_max' => '524288',
        'net.netfilter.nf_conntrack_generic_timeout' => '6',
        'net.netfilter.nf_conntrack_tcp_timeout_established' => '1200',
    ];
    $procSysRoot = pmssResolvePathFromEnv('PMSS_SYSCTL_PROC_SYS_PATH', '/proc/sys');
    $settings[is_file($procSysRoot.'/net/ipv4/netfilter/ip_conntrack_tcp_timeout_time_wait')
        ? 'net.ipv4.netfilter.ip_conntrack_tcp_timeout_time_wait'
        : 'net.netfilter.nf_conntrack_tcp_timeout_time_wait'] = '15';
    return $settings;
}

/** Build the ordered sysctl settings for the detected host profile. */
function pmssSysctlSettingsBuild(array $profile): array
{
    $settings = [
        'vm' => pmssSysctlMemorySettingsBuild($profile),
        'net' => pmssSysctlNetworkSettingsBuild($profile),
        'security' => pmssSysctlSecuritySettingsBuild(),
    ];
    $conntrackSettings = pmssSysctlConntrackSettingsBuild($profile);
    if ($conntrackSettings !== []) $settings['conntrack'] = $conntrackSettings;
    return $settings;
}

/** Parse one sysctl assignment while preserving each caller's comment policy. */
function pmssSysctlAssignmentLineParse(string $line, bool $stripInlineComment = false): ?array
{
    $trimmed = trim($stripInlineComment ? (string) preg_replace('/\s+#.*$/', '', $line) : $line);
    if ($trimmed === '' || $trimmed[0] === '#' || preg_match('/^([A-Za-z0-9_.]+)\s*=\s*(.*)$/', $trimmed, $matches) !== 1) {
        return null;
    }

    return [$matches[1], trim($matches[2])];
}

/** Parse operator-owned sysctl overrides from a config file. */
function pmssSysctlOverridesParse(string $path): array
{
    $keys = [];
    foreach (pmssReadRegularFileNonEmptyLines($path) as $line) if (($assignment = pmssSysctlAssignmentLineParse($line, true)) !== null) $keys[$assignment[0]] = true;
    return array_keys($keys);
}

/** Filter grouped sysctl settings while respecting explicit operator overrides. */
function pmssSysctlSettingsFilterOverrides(array $groupedSettings, array $overrideKeys): array
{
    $filtered = [];
    $overrides = array_flip($overrideKeys);
    foreach ($groupedSettings as $group => $settings) {
        if (!is_array($settings)) continue;
        foreach ($settings as $key => $value) if (!isset($overrides[$key])) $filtered[$group][$key] = (string) $value;
    }
    return $filtered;
}

/** Parse a sysctl config file into key/value pairs for change reporting. */
function pmssSysctlFileParse(string $path): array
{
    $settings = [];
    foreach (pmssReadRegularFileNonEmptyLines($path) as $line) if (($assignment = pmssSysctlAssignmentLineParse($line)) !== null && $assignment[1] !== '') $settings[$assignment[0]] = $assignment[1];
    return $settings;
}

/** Render grouped sysctl settings as an ordered config file body. */
function pmssSysctlConfigRender(array $groupedSettings): string
{
    $labels = [
        'vm' => 'Memory',
        'net' => 'Network',
        'conntrack' => 'Conntrack',
        'security' => 'Security Hardening',
    ];

    $lines = ['# Pulsed Media Config', '# Hardware-aware PMSS baseline'];
    foreach ($groupedSettings as $group => $settings) {
        if (empty($settings)) {
            continue;
        }

        $lines[] = '';
        $lines[] = '# '.($labels[$group] ?? ucfirst($group));
        foreach ($settings as $key => $value) {
            $lines[] = $key.' = '.$value;
        }
    }

    return implode(PHP_EOL, $lines).PHP_EOL;
}

/** Return ordered key/value rows from grouped sysctl settings. */
function pmssSysctlGroupedSettingsRows(array $groupedSettings): array
{
    $rows = [];
    foreach ($groupedSettings as $settings) {
        if (!is_array($settings)) continue;
        foreach ($settings as $key => $value) $rows[] = [(string) $key, (string) $value];
    }
    return $rows;
}

/** Describe value changes between the existing file and the next applied profile. */
function pmssSysctlChangesDescribe(array $existingSettings, array $groupedSettings): array
{
    $changes = [];
    foreach (pmssSysctlGroupedSettingsRows($groupedSettings) as $row) {
        [$key, $value] = $row;
        $previousValue = array_key_exists($key, $existingSettings) ? (string) $existingSettings[$key] : null;
        if ($previousValue === $value) continue;

        $changes[] = $previousValue === null
            ? $key.': <unset> -> '.$value
            : $key.': '.$previousValue.' -> '.$value;
    }

    return $changes;
}

/** Persist the detected sysctl profile to hardware.json without clobbering peers. */
function pmssSysctlSummaryWrite(?callable $logger, array $profile, array $groupedSettings, array $overrideKeys, array $changes): void
{
    $log = $logger ?: 'logMessage';
    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    if (!pmssDirEnsureExists($cfgDir, 0755)) {
        $log('[WARN] Unable to create hardware summary directory: '.$cfgDir);
        return;
    }

    $target = $cfgDir.'/hardware.json';
    $existing = @file_get_contents($target);
    $payload = is_string($existing) ? (pmssJsonDecodeAssoc($existing) ?? []) : [];

    $applied = [];
    foreach (pmssSysctlGroupedSettingsRows($groupedSettings) as $row) {
        $applied[$row[0]] = $row[1];
    }
    ksort($applied);

    $payload['timestamp'] = gmdate('Y-m-d\TH:i:s\Z');
    $payload['sysctl'] = [
        'detection' => [
            'ram_gb' => (int) ($profile['ram_gb'] ?? 0),
            'has_swap' => !empty($profile['has_swap']),
            'swap_is_fast' => !empty($profile['swap_is_fast']),
            'nic_speed_mbps' => (int) ($profile['nic_speed_mbps'] ?? 0),
            'nic_speed_gbps' => (int) ($profile['nic_speed_gbps'] ?? 0),
            'is_vm' => !empty($profile['is_vm']),
            'has_conntrack' => !empty($profile['has_conntrack']),
        ],
        'applied' => $applied,
        'overrides_respected' => array_values($overrideKeys),
        'changes_made' => array_values($changes),
    ];

    if (($json = pmssJsonEncodePrettyLine($payload)) === null) {
        $log('[WARN] Unable to encode hardware summary JSON for '.$target);
        return;
    }

    pmssWriteManagedPathFile(
        $target,
        $json,
        'hardware summary JSON',
        $log,
        null,
        null,
        0644,
        '[WARN] Unable to write hardware summary JSON at '.$target
    );
}
