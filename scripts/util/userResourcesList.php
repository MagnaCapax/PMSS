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

require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/userLifecycle.php';

$parsed = pmssParseCliTokens($argv);
$outputJsonl = (bool)pmssCliOption($parsed, 'jsonl');
$outputJson = !$outputJsonl && (bool)pmssCliOption($parsed, 'json');

$notSet = '[not set]';
$sliceKeys = [
    'MemoryHigh', 'MemoryMax',
    'CPUWeight', 'IOWeight',
    'CPUQuotaPerSecUSec', 'CPUQuotaPeriodUSec', 'CPUQuota',
    'IOReadBandwidthMax', 'IOWriteBandwidthMax',
    'IOReadIOPSMax', 'IOWriteIOPSMax',
];

$getSliceProperties = static function (string $slice) use ($notSet, $sliceKeys): array {
    $cmd = 'systemctl show '.escapeshellarg($slice).' -p '.implode(' -p ', $sliceKeys);
    $out = shell_exec($cmd);

    $data = array_fill_keys($sliceKeys, $notSet);
    if (!$out) {
        return $data;
    }

    foreach (explode("\n", trim($out)) as $line) {
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $key = $parts[0];
        $val = trim($parts[1]);
        if ($val === '' || $val === 'infinity' || $val === $notSet || (ctype_digit($val) && $val > 999999999999999)) {
            $data[$key] = $notSet;
            continue;
        }
        $data[$key] = $val;
    }

    return $data;
};

$formatBytes = static function ($val) use ($notSet): string {
    if ($val === $notSet) {
        return '-';
    }
    $bytes = (int) $val;
    if ($bytes === 0) {
        return '-';
    }

    $units = ['B', 'K', 'M', 'G', 'T'];
    $pow = (int) floor(log($bytes) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    return round($bytes, 1).$units[$pow];
};

$parseCpuQuota = static function (array $props) use ($notSet): ?int {
    if ($props['CPUQuota'] !== $notSet) {
        $val = $props['CPUQuota'];
        if (strpos($val, '%') !== false) {
            return (int) round((float) $val);
        }
    }
    if ($props['CPUQuotaPerSecUSec'] !== $notSet && $props['CPUQuotaPeriodUSec'] !== $notSet) {
        $period = (int) $props['CPUQuotaPeriodUSec'];
        if ($period > 0) {
            return (int) round(((int) $props['CPUQuotaPerSecUSec'] / $period) * 100);
        }
    }
    return null;
};

$parseIOPS = static function ($val) use ($notSet): ?int {
    if ($val === $notSet) {
        return null;
    }
    return preg_match('/([0-9]+)$/', $val, $matches) === 1 ? (int) $matches[1] : null;
};

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
    $props = $getSliceProperties($slice);
    $cpuQuotaPercent = $parseCpuQuota($props);
    $readIops = $parseIOPS($props['IOReadIOPSMax']);
    $writeIops = $parseIOPS($props['IOWriteIOPSMax']);

    $resourceData = [
        'user' => $user,
        'uid' => $info['uid'],
        'memory_high' => ($props['MemoryHigh'] === $notSet || (int) $props['MemoryHigh'] === 0) ? null : (int) $props['MemoryHigh'],
        'memory_max' => ($props['MemoryMax'] === $notSet || (int) $props['MemoryMax'] === 0) ? null : (int) $props['MemoryMax'],
        'cpu_weight' => ($props['CPUWeight'] !== '[not set]') ? (int)$props['CPUWeight'] : null,
        'cpu_quota_percent' => $cpuQuotaPercent,
        'io_weight' => ($props['IOWeight'] !== '[not set]') ? (int)$props['IOWeight'] : null,
        'io_read_bandwidth' => ($props['IOReadBandwidthMax'] === $notSet || (int) $props['IOReadBandwidthMax'] === 0) ? null : (int) $props['IOReadBandwidthMax'],
        'io_write_bandwidth' => ($props['IOWriteBandwidthMax'] === $notSet || (int) $props['IOWriteBandwidthMax'] === 0) ? null : (int) $props['IOWriteBandwidthMax'],
        'io_read_iops' => $readIops,
        'io_write_iops' => $writeIops,
    ];

    if ($outputJsonl) {
        echo json_encode($resourceData) . "\n";
    } elseif ($outputJson) {
        $allData[] = $resourceData;
    } else {
        printf(
            "%-15s %-8s %-8s %-8s %-10s %-10s %-12s %-12s %-12s %-12s\n",
            substr($user, 0, 15),
            $formatBytes($props['MemoryHigh']),
            $formatBytes($props['MemoryMax']),
            $resourceData['cpu_weight'] ?? '-',
            $cpuQuotaPercent === null ? '-' : $cpuQuotaPercent.'%',
            $resourceData['io_weight'] ?? '-',
            $formatBytes($props['IOReadBandwidthMax']),
            $formatBytes($props['IOWriteBandwidthMax']),
            $readIops === null ? '-' : (string) $readIops,
            $writeIops === null ? '-' : (string) $writeIops
        );
    }
}

if ($outputJson) {
    echo json_encode($allData, JSON_PRETTY_PRINT) . "\n";
}
