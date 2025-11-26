#!/usr/bin/php
<?php
/**
 * Display comprehensive resource limits for all users.
 *
 * Queries live systemd slice configuration to show actual applied limits
 * for RAM, CPU, and Disk I/O.
 */

if (posix_getuid() !== 0) {
    fwrite(STDERR, "Error: This script must be run as root to query systemd slices.\n");
    exit(1);
}

$usersRaw = shell_exec('/scripts/listUsers.php');
if ($usersRaw === null || trim($usersRaw) === '') {
    die("No users found.\n");
}
$users = explode("\n", trim($usersRaw));

// Table Headers
printf(
    "%-15s %-8s %-8s %-8s %-10s %-10s %-12s %-12s %-12s %-12s\n",
    "User", "MemHigh", "MemMax", "CPUWt", "CPUQuota", "IOWt", "ReadBW", "WriteBW", "ReadIOPS", "WriteIOPS"
);
echo str_repeat("-", 120) . "\n";

foreach ($users as $user) {
    $user = trim($user);
    if ($user === '') continue;

    $info = posix_getpwnam($user);
    if (!$info) continue;

    $slice = "user-" . $info['uid'] . ".slice";
    $props = getSliceProperties($slice);

    printf(
        "%-15s %-8s %-8s %-8s %-10s %-10s %-12s %-12s %-12s %-12s\n",
        substr($user, 0, 15),
        formatBytes($props['MemoryHigh']),
        formatBytes($props['MemoryMax']),
        $props['CPUWeight'] !== '[not set]' ? $props['CPUWeight'] : '-',
        formatCpuQuota($props),
        $props['IOWeight'] !== '[not set]' ? $props['IOWeight'] : '-',
        formatBytes($props['IOReadBandwidthMax']),
        formatBytes($props['IOWriteBandwidthMax']),
        formatIOPS($props['IOReadIOPSMax']),
        formatIOPS($props['IOWriteIOPSMax'])
    );
}

function getSliceProperties(string $slice): array {
    $keys = [
        'MemoryHigh', 'MemoryMax',
        'CPUWeight', 'IOWeight',
        'CPUQuotaPerSecUSec', 'CPUQuotaPeriodUSec', 'CPUQuota', // v2 quota
        'IOReadBandwidthMax', 'IOWriteBandwidthMax',
        'IOReadIOPSMax', 'IOWriteIOPSMax'
    ];

    $cmd = 'systemctl show ' . escapeshellarg($slice) . ' -p ' . implode(' -p ', $keys);
    $out = shell_exec($cmd);
    
    $data = array_fill_keys($keys, '[not set]');
    
    if ($out) {
        foreach (explode("\n", trim($out)) as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $val = trim($parts[1]);
                // systemd often returns "infinity" or "18446744073709551615" for unset limits
                if ($val === '' || $val === 'infinity' || $val === '[not set]') {
                    $data[$parts[0]] = '[not set]';
                } elseif (ctype_digit($val) && $val > 999999999999999) { // huge int
                     $data[$parts[0]] = '[not set]';
                } else {
                    $data[$parts[0]] = $val;
                }
            }
        }
    }
    return $data;
}

function formatBytes($val): string {
    if ($val === '[not set]') return '-';
    $bytes = (int)$val;
    if ($bytes === 0) return '-';
    
    $units = ['B', 'K', 'M', 'G', 'T'];
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, 1) . $units[$pow];
}

function formatCpuQuota(array $props): string {
    // Try straightforward v2 property first
    if ($props['CPUQuota'] !== '[not set]') {
        return $props['CPUQuota'];
    }
    // Try calculating from period
    if ($props['CPUQuotaPerSecUSec'] !== '[not set]' && $props['CPUQuotaPeriodUSec'] !== '[not set]') {
         $p = (int)$props['CPUQuotaPeriodUSec'];
         if ($p > 0) {
             $pct = round(((int)$props['CPUQuotaPerSecUSec'] / $p) * 100);
             return $pct . '%';
         }
    }
    return '-';
}

function formatIOPS($val): string {
    if ($val === '[not set]') return '-';
    // Systemd returns "Device Node Path Value", we just want Value? 
    // Actually systemd IO props are often "path value", e.g. "/dev/sda 100".
    // If multiple devices, it might be multiline or space separated.
    // For simple display, we'll just show "set" or the raw string if short.
    return (strlen($val) > 10) ? 'Yes' : $val;
}
