<?php
/** Host hardware discovery for the managed sysctl profile. @license GPL-3.0-only */

require_once dirname(__DIR__, 2).'/network/interface.php';

/** Read a boolean-like environment override when present. */
function pmssSystemPrepReadBoolEnv(string $key): ?bool
{
    $override = getenv($key);
    if ($override === false) return null;
    $normalized = pmssEnvValueNormalized($override);
    if (in_array($normalized, ['1', 'true', 'yes'], true)) return true;
    return in_array($normalized, ['0', 'false', 'no'], true) ? false : null;
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
    $devicePath = rtrim($sysClassBlockRoot, '/').'/'.$deviceName;
    $queuePath = $devicePath.'/queue/rotational';
    if (is_file($queuePath)) {
        return (pmssReadRegularFileTrimmed($queuePath) ?? '') === '0';
    }

    foreach (glob($devicePath.'/slaves/*') ?: [] as $slavePath) {
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
    foreach (array_slice(pmssReadRegularFileNonEmptyLines('/proc/swaps'), 1) as $line) {
        if (($columns = pmssConfigLineColumns($line, 1, [])) === []) {
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
    if (($override = pmssEnvReadDigits('PMSS_SYSCTL_NIC_SPEED_MBPS')) !== null) {
        return $override;
    }

    $routePath = pmssResolvePathFromEnv('PMSS_SYSCTL_PROC_NET_ROUTE_PATH', '/proc/net/route');
    $iface = '';
    foreach (array_slice(pmssReadRegularFileNonEmptyLines($routePath), 1) as $line) {
        $columns = pmssConfigLineColumns($line, 2, []);
        if ($columns !== [] && $columns[1] === '00000000') {
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
    // A malformed sysfs sample must not saturate into a fictitious fast link.
    return pmssUnsignedDecimalIntParse($speed) ?? 1000;
}

/** Detect whether the current host is a virtual machine. */
function pmssSysctlIsVm(): bool
{
    if (($override = pmssSystemPrepReadBoolEnv('PMSS_SYSCTL_IS_VM')) !== null) return $override;

    if (($systemdDetectVirt = pmssCommandPath('systemd-detect-virt')) !== '' && function_exists('exec')) {
        $status = 1;
        $output = [];
        @exec(pmssCommandArgvShellQuote([$systemdDetectVirt, '--quiet']).' >/dev/null 2>&1', $output, $status);
        return $status === 0;
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
    $nicSpeedMbps = max(0, pmssSysctlNicSpeedMbps());
    $hasSwap = pmssSysctlHasSwap();

    return [
        'ram_gb' => max(1, (int) ceil($totalMemMiB / 1024)),
        'total_mem_mib' => $totalMemMiB,
        'has_swap' => $hasSwap,
        'swap_is_fast' => $hasSwap && pmssSysctlSwapIsFast(),
        'nic_speed_mbps' => $nicSpeedMbps,
        'nic_speed_gbps' => $nicSpeedMbps >= 10000 ? 10 : 1,
        'is_vm' => pmssSysctlIsVm(),
        'has_conntrack' => pmssSysctlHasConntrack(),
    ];
}
