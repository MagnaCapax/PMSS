#!/usr/bin/php
<?php
/**
 * Display comprehensive resource limits for all users.
 *
 * Queries live systemd slice configuration to show actual applied limits
 * for RAM, CPU, and Disk I/O.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 */

if (posix_getuid() !== 0) {
    fwrite(STDERR, "Error: This script must be run as root to query systemd slices.\n");
    exit(1);
}

require_once __DIR__.'/../lib/cli/OptionParser.php';
require_once __DIR__.'/../lib/userLifecycle.php';

$parsed = pmssParseCliTokens($argv);
$outputJsonl = (bool)pmssCliOption($parsed, 'jsonl');
$outputJson = !$outputJsonl && (bool)pmssCliOption($parsed, 'json');

$usersRaw = shell_exec('/scripts/listUsers.php');
if ($usersRaw === null || trim($usersRaw) === '') {
    if ($outputJson) {
        echo "[]\n";
    } elseif (!$outputJsonl) {
        echo "No users found.\n";
    }
    exit(0);
}
$users = explode("\n", trim($usersRaw));

if (!$outputJson && !$outputJsonl) {
    // Table Headers
    printf(
        "%-15s %-8s %-8s %-8s %-10s %-10s %-12s %-12s %-12s %-12s\n",
        "User", "MemHigh", "MemMax", "CPUWt", "CPUQuota", "IOWt", "ReadBW", "WriteBW", "ReadIOPS", "WriteIOPS"
    );
    echo str_repeat("-", 120) . "\n";
}

$allData = [];

foreach ($users as $user) {
    $user = trim($user);
    if ($user === '') {
        continue;
    }
    if (!pmssValidateUsername($user)) {
        pmssUserWriteLogs(
            pmssUserBaseContext(
                'resources',
                'validate',
                $user,
                [
                    'status'  => 'ERR',
                    'message' => 'Skipping invalid username in userResourcesList',
                ]
            )
        );
        continue;
    }

    $info = posix_getpwnam($user);
    if (!$info) continue;

    $slice = "user-{$info['uid']}.slice";
    $props = getSliceProperties($slice);

    $resourceData = [
        'user' => $user,
        'uid' => $info['uid'],
        'memory_high' => parseBytes($props['MemoryHigh']),
        'memory_max' => parseBytes($props['MemoryMax']),
        'cpu_weight' => ($props['CPUWeight'] !== '[not set]') ? (int)$props['CPUWeight'] : null,
        'cpu_quota_percent' => parseCpuQuota($props, true),
        'io_weight' => ($props['IOWeight'] !== '[not set]') ? (int)$props['IOWeight'] : null,
        'io_read_bandwidth' => parseBytes($props['IOReadBandwidthMax']),
        'io_write_bandwidth' => parseBytes($props['IOWriteBandwidthMax']),
        'io_read_iops' => parseIOPS($props['IOReadIOPSMax'], true),
        'io_write_iops' => parseIOPS($props['IOWriteIOPSMax'], true),
    ];

    if ($outputJsonl) {
        echo json_encode($resourceData) . "\n";
    } elseif ($outputJson) {
        $allData[] = $resourceData;
    } else {
        printf(
            "%-15s %-8s %-8s %-8s %-10s %-10s %-12s %-12s %-12s %-12s\n",
            substr($user, 0, 15),
            formatBytes($props['MemoryHigh']),
            formatBytes($props['MemoryMax']),
            $resourceData['cpu_weight'] ?? '-',
            formatCpuQuota($props, false),
            $resourceData['io_weight'] ?? '-',
            formatBytes($props['IOReadBandwidthMax']),
            formatBytes($props['IOWriteBandwidthMax']),
            formatIOPS($props['IOReadIOPSMax'], false),
            formatIOPS($props['IOWriteIOPSMax'], false)
        );
    }
}

if ($outputJson) {
    echo json_encode($allData, JSON_PRETTY_PRINT) . "\n";
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

function parseBytes($val): ?int {
    if ($val === '[not set]') return null;
    $bytes = (int)$val;
    if ($bytes === 0) return null;
    return $bytes;
}

function formatCpuQuota(array $props, bool $forJson = false): string {
    $quota = parseCpuQuota($props, true); // Get raw value for consistent parsing
    if ($quota === null) {
        return $forJson ? 'null' : '-';
    }
    return $quota . '%';
}

function parseCpuQuota(array $props, bool $raw = false): ?int {
    // Try straightforward v2 property first
    if ($props['CPUQuota'] !== '[not set]') {
        $val = $props['CPUQuota'];
        if (strpos($val, '%') !== false) {
            return (int)round((float)$val);
        }
    }
    // Try calculating from period
    if ($props['CPUQuotaPerSecUSec'] !== '[not set]' && $props['CPUQuotaPeriodUSec'] !== '[not set]') {
         $p = (int)$props['CPUQuotaPeriodUSec'];
         if ($p > 0) {
             return (int)round(((int)$props['CPUQuotaPerSecUSec'] / $p) * 100);
         }
    }
    return null;
}

function formatIOPS($val, bool $forJson = false): string {
    $iops = parseIOPS($val, true);
    if ($iops === null) {
        return $forJson ? 'null' : '-';
    }
    return (string)$iops;
}

function parseIOPS($val, bool $raw = false): ?int {
    if ($val === '[not set]') return null;
    // systemd IO props are often "path value", e.g. "/dev/sda 100".
    // For JSON, we want the numeric value.
    if (preg_match('/([0-9]+)$/', $val, $matches)) {
        return (int)$matches[1];
    }
    return null;
}
