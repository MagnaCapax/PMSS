#!/usr/bin/env php
<?php
/**
 * Display comprehensive resource limits for all users.
 *
 * Queries live systemd slice configuration to show actual applied limits
 * for RAM, CPU, and Disk I/O.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */

if (posix_getuid() !== 0) {
    fwrite(STDERR, "Error: This script must be run as root to query systemd slices.\n");
    exit(1);
}

require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/user/userConfigStore.php';

$parsed = pmssParseCliTokens($argv);
$outputJsonl = (bool)pmssCliOption($parsed, 'jsonl');
$outputJson = !$outputJsonl && (bool)pmssCliOption($parsed, 'json');
$briefMode = (bool)pmssCliOption($parsed, 'brief');
$fullMode = (bool)pmssCliOption($parsed, 'full');

if ($briefMode && $fullMode) {
    fwrite(STDERR, "Error: choose either --brief or --full (not both).\n");
    exit(1);
}
$displayMode = $fullMode ? 'full' : 'brief';
$columnFormats = [
    'brief' => "%-10s %-5s %-8s %-8s %-6s %-6s %-6s %-6s %-6s %-7s %-7s\n",
    'full' => "%-10s %-5s %-8s %-8s %-6s %-6s %-6s %-6s %-6s %-7s %-7s %-6s %-6s %-7s %-7s %-7s %-7s %-8s %-9s\n",
];
$columnHeaders = [
    'brief' => ["User", "UID", "MemHigh", "MemMax", "CPUWt", "CPUQt", "BlkWt", "RdBW", "WrBW", "RdIOPS", "WrIOPS"],
    'full' => ["User", "UID", "MemHigh", "MemMax", "CPUWt", "CPUQt", "BlkWt", "RdBW", "WrBW", "RdIOPS", "WrIOPS", "DskQ", "DskB", "InoQ", "InoB", "NetLim", "NetUsed", "ProcMax", "Suspended"],
];
$columnSeparatorWidths = ['brief' => 120, 'full' => 180];

$notSet = '[not set]';
$sliceKeys = [
    'MemoryHigh', 'MemoryMax',
    'CPUWeight', 'IOWeight',
    'CPUQuotaPerSecUSec', 'CPUQuotaPeriodUSec', 'CPUQuota',
    'IOReadBandwidthMax', 'IOWriteBandwidthMax',
    'IOReadIOPSMax', 'IOWriteIOPSMax',
    'TasksMax',
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

$parseTrailingInt = static function ($val) use ($notSet): ?int {
    if (!is_string($val) || $val === '' || $val === $notSet || $val === 'infinity') {
        return null;
    }
    if (preg_match('/(\d+)\s*$/', trim($val), $matches) !== 1) {
        return null;
    }
    $parsed = (int) $matches[1];
    if ($parsed <= 0) {
        return null;
    }
    return $parsed;
};

$formatBinary = static function (?int $bytes): string {
    if ($bytes === null || $bytes <= 0) {
        return '-';
    }

    $units = ['B', 'K', 'M', 'G', 'T'];
    $pow = (int) floor(log($bytes) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    $rounded = round($bytes, 1);
    if (abs($rounded - (int) $rounded) < 0.05) {
        $rounded = (int) $rounded;
    }

    return $rounded.$units[$pow];
};

$formatGiB = static function ($value): string {
    if ($value === null) {
        return '-';
    }

    $gib = (float) $value;
    $rounded = round($gib, 1);
    if (abs($rounded - (int) $rounded) < 0.05) {
        $rounded = (int) $rounded;
    }

    return $rounded.'G';
};

$store = new UserConfigStore();

$usersRaw = trim((string) shell_exec('/scripts/listUsers.php'));
if ($usersRaw === '') {
    if ($outputJson) {
        echo "[]\n";
    } elseif (!$outputJsonl) {
        echo "No users found.\n";
    }
    exit(0);
}
$users = explode("\n", $usersRaw);

if (!$outputJson && !$outputJsonl) {
    printf($columnFormats[$displayMode], ...$columnHeaders[$displayMode]);
    echo str_repeat('-', $columnSeparatorWidths[$displayMode])."\n";
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
    $cpuQuotaPercent = null;
    if ($props['CPUQuota'] !== $notSet && strpos($props['CPUQuota'], '%') !== false) {
        $cpuQuotaPercent = (int) round((float) $props['CPUQuota']);
    } elseif ($props['CPUQuotaPerSecUSec'] !== $notSet && $props['CPUQuotaPeriodUSec'] !== $notSet) {
        $cpuQuotaPeriod = (int) $props['CPUQuotaPeriodUSec'];
        if ($cpuQuotaPeriod > 0) {
            $cpuQuotaPercent = (int) round(((int) $props['CPUQuotaPerSecUSec'] / $cpuQuotaPeriod) * 100);
        }
    }
    $readIops = $parseTrailingInt($props['IOReadIOPSMax']);
    $writeIops = $parseTrailingInt($props['IOWriteIOPSMax']);
    $memoryHigh = $parseTrailingInt($props['MemoryHigh']);
    $memoryMax = $parseTrailingInt($props['MemoryMax']);
    $cpuWeight = ($props['CPUWeight'] !== $notSet) ? (int) $props['CPUWeight'] : null;
    $ioReadBandwidth = $parseTrailingInt($props['IOReadBandwidthMax']);
    $ioWriteBandwidth = $parseTrailingInt($props['IOWriteBandwidthMax']);
    $ioWeight = ($props['IOWeight'] !== $notSet) ? (int) $props['IOWeight'] : null;
    $tasksMax = $parseTrailingInt($props['TasksMax']);

    $userConfig = $store->get($user);
    $diskQuotaGiB = null;
    $diskBurstGiB = null;
    $inodeQuota = null;
    $inodeBurst = null;
    $suspended = is_dir("/home/{$user}/www-disabled");

    if (is_array($userConfig)) {
        if (isset($userConfig['quota']) && is_numeric($userConfig['quota'])) {
            $diskQuotaGiB = (int) $userConfig['quota'];
        }
        if (isset($userConfig['quotaBurst']) && is_numeric($userConfig['quotaBurst'])) {
            $diskBurstGiB = (int) $userConfig['quotaBurst'];
        }
        if ($diskBurstGiB === null && $diskQuotaGiB !== null) {
            $diskBurstGiB = (int) round($diskQuotaGiB * 1.25);
        }
        if ($diskQuotaGiB !== null && $diskQuotaGiB > 0) {
            $inodeQuota = max($diskQuotaGiB * 500, 15000);
        }
        if ($inodeQuota !== null) {
            $inodeBurst = (int) floor($inodeQuota * 1.25);
        }
        if (!$suspended && isset($userConfig['suspended'])) {
            $suspended = (bool) $userConfig['suspended'];
        }
    }

    $trafficLimitGiB = null;
    $trafficLimitPath = "/home/{$user}/.trafficLimit";
    if (is_file($trafficLimitPath) && !is_link($trafficLimitPath)) {
        $rawTrafficLimit = trim((string) @file_get_contents($trafficLimitPath));
        if ($rawTrafficLimit !== '' && is_numeric($rawTrafficLimit)) {
            $parsedTrafficLimit = (int) $rawTrafficLimit;
            if ($parsedTrafficLimit > 0) {
                $trafficLimitGiB = $parsedTrafficLimit;
            }
        }
    }

    $trafficUsedGiB = 0.0;
    $trafficDataPath = "/home/{$user}/.trafficData";
    if (is_file($trafficDataPath) && !is_link($trafficDataPath)) {
        $rawTrafficData = @file_get_contents($trafficDataPath);
        if (is_string($rawTrafficData) && $rawTrafficData !== '') {
            $trafficData = @unserialize($rawTrafficData, ['allowed_classes' => false]);
            if (is_array($trafficData) && isset($trafficData['raw']['month']) && is_numeric($trafficData['raw']['month'])) {
                $trafficUsedGiB = round(((float) $trafficData['raw']['month']) / 1024, 1);
            }
        }
    }

    $resourceData = [
        'user' => $user,
        'uid' => $info['uid'],
        'memory_high' => $memoryHigh,
        'memory_max' => $memoryMax,
        'cpu_weight' => $cpuWeight,
        'cpu_quota_percent' => $cpuQuotaPercent,
        'io_weight' => $ioWeight,
        'io_read_bandwidth' => $ioReadBandwidth,
        'io_write_bandwidth' => $ioWriteBandwidth,
        'io_read_iops' => $readIops,
        'io_write_iops' => $writeIops,
        'disk_quota_gib' => $diskQuotaGiB,
        'disk_burst_gib' => $diskBurstGiB,
        'inode_quota' => $inodeQuota,
        'inode_burst' => $inodeBurst,
        'network_limit_gib' => $trafficLimitGiB,
        'network_used_gib' => $trafficUsedGiB,
        'process_max' => $tasksMax,
        'suspended' => $suspended,
    ];

    if ($outputJsonl) {
        echo json_encode($resourceData) . "\n";
    } elseif ($outputJson) {
        $allData[] = $resourceData;
    } else {
        $row = [
            substr($user, 0, 10),
            (string) $info['uid'],
            $formatBinary($memoryHigh),
            $formatBinary($memoryMax),
            $cpuWeight === null ? '-' : (string) $cpuWeight,
            $cpuQuotaPercent === null ? '-' : $cpuQuotaPercent.'%',
            $ioWeight === null ? '-' : (string) $ioWeight,
            $formatBinary($ioReadBandwidth),
            $formatBinary($ioWriteBandwidth),
            $readIops === null ? '-' : (string) $readIops,
            $writeIops === null ? '-' : (string) $writeIops,
        ];
        if ($displayMode === 'full') {
            $row = array_merge($row, [
                $diskQuotaGiB === null ? '-' : $formatGiB($diskQuotaGiB),
                $diskBurstGiB === null ? '-' : $formatGiB($diskBurstGiB),
                $inodeQuota === null ? '-' : (string) $inodeQuota,
                $inodeBurst === null ? '-' : (string) $inodeBurst,
                $trafficLimitGiB === null ? 'inf' : $formatGiB($trafficLimitGiB),
                $formatGiB($trafficUsedGiB),
                $tasksMax === null ? 'inf' : (string) $tasksMax,
                $suspended ? 'yes' : 'no',
            ]);
        }
        printf($columnFormats[$displayMode], ...$row);
    }
}

if ($outputJson) {
    echo json_encode($allData, JSON_PRETTY_PRINT) . "\n";
}
