<?php
/** Build managed sysctl policy from a host profile. @license GPL-3.0-only */

require_once dirname(__DIR__, 2).'/portManager.php';
require_once __DIR__.'/sysctlText.php';

/** Build memory sysctl settings for the detected host profile. */
function pmssSysctlMemorySettingsBuild(array $profile): array
{
    $hasSwap = !empty($profile['has_swap']);
    // No-swap and VM policies take precedence over fast storage.
    $fastSwap = $hasSwap && empty($profile['is_vm']) && !empty($profile['swap_is_fast']);
    $ramGb = max(1, (int) ($profile['ram_gb'] ?? 1));

    return [
        'vm.swappiness' => !$hasSwap ? '60' : ($fastSwap ? '100' : '10'),
        'vm.vfs_cache_pressure' => $fastSwap ? '2' : '50',
        // 1 GiB floor = the long-standing legacy 99-pmss.conf value (#221 baseline); RAM-scaled above it.
        'vm.min_free_kbytes' => (string) min($fastSwap ? 4194304 : 2097152, max(1048576, $ramGb * ($fastSwap ? 10240 : 5120))),
        'vm.dirty_ratio' => $fastSwap ? '40' : '20',
        'vm.dirty_background_ratio' => $fastSwap ? '10' : '5',
        'vm.dirty_expire_centisecs' => '1500',
        'vm.dirty_writeback_centisecs' => '500',
    ];
}

/** Build network sysctl settings for the detected host profile. */
function pmssSysctlNetworkSettingsBuild(array $profile): array
{
    $tenGigabit = (int) ($profile['nic_speed_gbps'] ?? 1) >= 10;
    $networkBufferBytes = $tenGigabit ? '67108864' : '16777216';
    $networkTcpBytes = $tenGigabit ? '134217728' : '67110000';
    return array_fill_keys(['net.core.rmem_max', 'net.core.wmem_max', 'net.core.rmem_default', 'net.core.wmem_default', 'net.core.optmem_max'], $networkBufferBytes) + [
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
        // Keep account processes below the UI ports unable to bind them.
        'net.ipv4.ip_unprivileged_port_start' => '1024',
        // Keep the kernel reservation tied to the allocator band (ADR 0026).
        'net.ipv4.ip_local_reserved_ports' => PMSS_PORT_MANAGER_MIN_PORT.'-'.PMSS_PORT_MANAGER_MAX_PORT,
        'net.ipv4.tcp_mem' => '3086631 4115510 6173262',
        'net.ipv4.ip_forward' => '1',
    ];
}

/** Build PMSS security sysctl settings. */
function pmssSysctlSecuritySettingsBuild(): array
{
    $procSysRoot = pmssResolvePathFromEnv('PMSS_SYSCTL_PROC_SYS_PATH', '/proc/sys');
    // MPTCP off where the kernel has it: no PMSS service uses it, and Go 1.24+ programs open MPTCP listeners by
    // default, which widens the kernel network attack surface for no gain. New sockets fall back to plain TCP.
    $mptcp = is_file($procSysRoot.'/net/mptcp/enabled') ? ['net.mptcp.enabled' => '0'] : [];

    return [
        'kernel.pid_max' => '262144',
        'kernel.unprivileged_userns_clone' => '1',
        'fs.suid_dumpable' => '0',
        'fs.file-max' => '3000000',
        'fs.inotify.max_queued_events' => '65536',
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
    ] + $mptcp;
}

/** Build conntrack settings only when the host exposes conntrack sysctls. */
function pmssSysctlConntrackSettingsBuild(array $profile): array
{
    if (empty($profile['has_conntrack'])) return [];

    $procSysRoot = pmssResolvePathFromEnv('PMSS_SYSCTL_PROC_SYS_PATH', '/proc/sys');
    return [
        'net.netfilter.nf_conntrack_max' => '524288',
        'net.netfilter.nf_conntrack_generic_timeout' => '6',
        'net.netfilter.nf_conntrack_tcp_timeout_established' => '1200',
        (is_file($procSysRoot.'/net/ipv4/netfilter/ip_conntrack_tcp_timeout_time_wait')
            ? 'net.ipv4.netfilter.ip_conntrack_tcp_timeout_time_wait'
            : 'net.netfilter.nf_conntrack_tcp_timeout_time_wait') => '15',
    ];
}

/** Build the ordered sysctl settings for the detected host profile. */
function pmssSysctlSettingsBuild(array $profile): array
{
    $settings = [
        'vm' => pmssSysctlMemorySettingsBuild($profile),
        'net' => pmssSysctlNetworkSettingsBuild($profile),
        'security' => pmssSysctlSecuritySettingsBuild(),
    ];
    if (($conntrackSettings = pmssSysctlConntrackSettingsBuild($profile)) !== []) $settings['conntrack'] = $conntrackSettings;
    return $settings;
}
