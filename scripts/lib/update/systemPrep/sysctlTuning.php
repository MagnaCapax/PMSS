<?php
/**
 * Hardware-aware sysctl profile helpers for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

/** Read a boolean-like environment override when present. */
function pmssSystemPrepReadBoolEnv(string $key): ?bool
{
    $override = getenv($key);
    if ($override === false) {
        return null;
    }

    $value = strtolower(trim((string) $override));
    if ($value === '1' || $value === 'true' || $value === 'yes') {
        return true;
    }
    if ($value === '0' || $value === 'false' || $value === 'no') {
        return false;
    }

    return null;
}

/** Detect whether any swap device is configured. */
function pmssSysctlHasSwap(): bool
{
    if (($override = pmssSystemPrepReadBoolEnv('PMSS_SYSCTL_HAS_SWAP')) !== null) {
        return $override;
    }

    $lines = @file('/proc/swaps', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return is_array($lines) && count($lines) > 1;
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
        return trim((string) @file_get_contents($queuePath)) === '0';
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
    if (($override = pmssSystemPrepReadBoolEnv('PMSS_SYSCTL_SWAP_IS_FAST')) !== null) {
        return $override;
    }

    if (!pmssSysctlHasSwap()) {
        return false;
    }

    $swaps = @file('/proc/swaps', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($swaps)) {
        return false;
    }

    $sysClassBlockRoot = pmssResolvePathFromEnv('PMSS_SYSCTL_SYS_CLASS_BLOCK_PATH', '/sys/class/block');
    foreach ($swaps as $index => $line) {
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
    $lines = @file($routePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return 1000;
    }

    $iface = '';
    foreach ($lines as $index => $line) {
        if ($index === 0) {
            continue;
        }

        $columns = preg_split('/\s+/', trim($line));
        if (is_array($columns) && isset($columns[0], $columns[1]) && $columns[1] === '00000000') {
            $iface = (string) $columns[0];
            break;
        }
    }

    if ($iface === '') {
        return 1000;
    }

    $speedPath = pmssResolvePathFromEnv('PMSS_SYSCTL_SYS_CLASS_NET_PATH', '/sys/class/net').'/'.$iface.'/speed';
    $speed = is_file($speedPath) ? trim((string) @file_get_contents($speedPath)) : '';
    return ctype_digit($speed) ? (int) $speed : 1000;
}

/** Detect whether the current host is a virtual machine. */
function pmssSysctlIsVm(): bool
{
    if (($override = pmssSystemPrepReadBoolEnv('PMSS_SYSCTL_IS_VM')) !== null) {
        return $override;
    }

    if (($systemdDetectVirt = pmssCommandPath('systemd-detect-virt')) !== '') {
        return trim((string) @shell_exec(escapeshellcmd($systemdDetectVirt).' --quiet >/dev/null 2>&1; echo $?')) === '0';
    }

    foreach (['/sys/class/dmi/id/product_name', '/sys/class/dmi/id/sys_vendor'] as $path) {
        $value = strtolower(trim((string) @file_get_contents($path)));
        if ($value !== '' && preg_match('/kvm|vmware|virtualbox|qemu|bochs|openstack|hvm domu|xen/', $value)) {
            return true;
        }
    }

    return false;
}

/** Detect whether conntrack sysctls are available on this host. */
function pmssSysctlHasConntrack(): bool
{
    if (($override = pmssSystemPrepReadBoolEnv('PMSS_SYSCTL_HAS_CONNTRACK')) !== null) {
        return $override;
    }

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

/** Build the ordered sysctl settings for the detected host profile. */
function pmssSysctlSettingsBuild(array $profile): array
{
    $fastSwap = !empty($profile['swap_is_fast']);
    $hasSwap = !empty($profile['has_swap']);
    $isVm = !empty($profile['is_vm']);
    $ramGb = max(1, (int) ($profile['ram_gb'] ?? 1));
    $tenGigabit = (int) ($profile['nic_speed_gbps'] ?? 1) >= 10;

    if ($isVm) {
        $memorySettings = [
            'vm.swappiness' => '10',
            'vm.vfs_cache_pressure' => '50',
            'vm.min_free_kbytes' => '131072',
            'vm.dirty_ratio' => '20',
            'vm.dirty_background_ratio' => '5',
        ];
    } elseif ($fastSwap) {
        $memorySettings = [
            'vm.swappiness' => '100',
            'vm.vfs_cache_pressure' => '2',
            'vm.min_free_kbytes' => (string) min(4194304, max(131072, $ramGb * 10240)),
            'vm.dirty_ratio' => '40',
            'vm.dirty_background_ratio' => '10',
        ];
    } elseif ($hasSwap) {
        $memorySettings = [
            'vm.swappiness' => '10',
            'vm.vfs_cache_pressure' => '50',
            'vm.min_free_kbytes' => (string) min(2097152, max(131072, $ramGb * 5120)),
            'vm.dirty_ratio' => '20',
            'vm.dirty_background_ratio' => '5',
        ];
    } else {
        $memorySettings = [
            'vm.swappiness' => '60',
            'vm.vfs_cache_pressure' => '50',
            'vm.min_free_kbytes' => (string) min(2097152, max(131072, $ramGb * 5120)),
            'vm.dirty_ratio' => '20',
            'vm.dirty_background_ratio' => '5',
        ];
    }

    $memorySettings['vm.dirty_expire_centisecs'] = '1500';
    $memorySettings['vm.dirty_writeback_centisecs'] = '500';

    $networkAdaptiveSettings = $tenGigabit
        ? [
            'net.core.rmem_max' => '67108864',
            'net.core.wmem_max' => '67108864',
            'net.core.rmem_default' => '67108864',
            'net.core.wmem_default' => '67108864',
            'net.core.optmem_max' => '67108864',
            'net.core.netdev_max_backlog' => '524288',
            'net.core.somaxconn' => '4096',
            'net.ipv4.tcp_rmem' => '4096 524000 134217728',
            'net.ipv4.tcp_wmem' => '4096 524000 134217728',
        ]
        : [
            'net.core.rmem_max' => '16777216',
            'net.core.wmem_max' => '16777216',
            'net.core.rmem_default' => '16777216',
            'net.core.wmem_default' => '16777216',
            'net.core.optmem_max' => '16777216',
            'net.core.netdev_max_backlog' => '262144',
            'net.core.somaxconn' => '2000',
            'net.ipv4.tcp_rmem' => '4096 524000 67110000',
            'net.ipv4.tcp_wmem' => '4096 524000 67110000',
        ];

    $settings = [
        'vm' => $memorySettings,
        'net' => array_merge($networkAdaptiveSettings, [
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
        ]),
        'security' => [
            'kernel.pid_max' => '262144',
            'kernel.unprivileged_userns_clone' => '1',
            'fs.suid_dumpable' => '0',
            'fs.file-max' => '3000000',
            'fs.protected_regular' => '2',
            'fs.protected_fifos' => '2',
            'kernel.yama.ptrace_scope' => '1',
            'kernel.kptr_restrict' => '1',
            'net.ipv4.conf.all.rp_filter' => '1',
            'net.ipv4.conf.all.accept_source_route' => '0',
            'net.ipv4.conf.all.send_redirects' => '0',
            'net.ipv4.icmp_echo_ignore_broadcasts' => '1',
            'net.ipv4.icmp_ignore_bogus_error_responses' => '1',
            'net.ipv6.conf.all.disable_ipv6' => '1',
            'net.ipv6.conf.default.disable_ipv6' => '1',
        ],
    ];

    if (!empty($profile['has_conntrack'])) {
        $settings['conntrack'] = [
            'net.netfilter.nf_conntrack_max' => '524288',
            'net.netfilter.nf_conntrack_generic_timeout' => '6',
            'net.netfilter.nf_conntrack_tcp_timeout_established' => '1200',
        ];

        $procSysRoot = pmssResolvePathFromEnv('PMSS_SYSCTL_PROC_SYS_PATH', '/proc/sys');
        if (is_file($procSysRoot.'/net/ipv4/netfilter/ip_conntrack_tcp_timeout_time_wait')) {
            $settings['conntrack']['net.ipv4.netfilter.ip_conntrack_tcp_timeout_time_wait'] = '15';
        } else {
            $settings['conntrack']['net.netfilter.nf_conntrack_tcp_timeout_time_wait'] = '15';
        }
    }

    return $settings;
}

/** Parse operator-owned sysctl overrides from a config file. */
function pmssSysctlOverridesParse(string $path): array
{
    $keys = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    foreach ($lines as $line) {
        $line = trim((string) preg_replace('/\s+#.*$/', '', $line));
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (preg_match('/^([A-Za-z0-9_.]+)\s*=/', $line, $matches) === 1) {
            $keys[$matches[1]] = true;
        }
    }

    return array_keys($keys);
}

/** Filter grouped sysctl settings while respecting explicit operator overrides. */
function pmssSysctlSettingsFilterOverrides(array $groupedSettings, array $overrideKeys): array
{
    $flattened = [];
    foreach ($groupedSettings as $group => $settings) {
        if (!is_array($settings)) {
            continue;
        }

        foreach ($settings as $key => $value) {
            if (!in_array($key, $overrideKeys, true)) {
                $flattened[$group][$key] = (string) $value;
            }
        }
    }

    return $flattened;
}

/** Parse a sysctl config file into key/value pairs for change reporting. */
function pmssSysctlFileParse(string $path): array
{
    $settings = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '' || $trimmed[0] === '#') {
            continue;
        }

        if (preg_match('/^([A-Za-z0-9_.]+)\s*=\s*(.+)$/', $trimmed, $matches) === 1) {
            $settings[$matches[1]] = trim($matches[2]);
        }
    }

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

/** Describe value changes between the existing file and the next applied profile. */
function pmssSysctlChangesDescribe(array $existingSettings, array $groupedSettings): array
{
    $changes = [];
    foreach ($groupedSettings as $settings) {
        if (!is_array($settings)) {
            continue;
        }

        foreach ($settings as $key => $value) {
            $previousValue = array_key_exists($key, $existingSettings) ? (string) $existingSettings[$key] : null;
            if ($previousValue === (string) $value) {
                continue;
            }

            $changes[] = $previousValue === null
                ? $key.': <unset> -> '.$value
                : $key.': '.$previousValue.' -> '.$value;
        }
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
    $payload = [];
    if ($existing !== false) {
        $decoded = json_decode($existing, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $applied = [];
    foreach ($groupedSettings as $settings) {
        if (!is_array($settings)) {
            continue;
        }
        foreach ($settings as $key => $value) {
            $applied[$key] = $value;
        }
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

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $log('[WARN] Unable to encode hardware summary JSON for '.$target);
        return;
    }

    $tmpTarget = $target.'.tmp';
    if (@file_put_contents($tmpTarget, $json.PHP_EOL) === false) {
        $log('[WARN] Unable to write hardware summary JSON at '.$tmpTarget);
        return;
    }

    @chmod($tmpTarget, 0644);
    if (!@rename($tmpTarget, $target)) {
        $log('[WARN] Unable to install hardware summary JSON at '.$target);
        @unlink($tmpTarget);
    }
}
