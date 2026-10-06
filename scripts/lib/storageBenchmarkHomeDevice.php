<?php
/** Read-only benchmark of the block device mounted below the selected target. */

/** Validate a read target without requiring write access to customer storage. */
function storageBenchmarkHomeDeviceRequireTarget(string $targetDir): string
{
    $path = rtrim($targetDir, '/');
    if ($path === '' || preg_match('/[\r\n\0]/', $path) === 1
        || !pmssPathSegmentsAreSafe($path, true, true, true, true)) {
        storageBenchmarkFail("Error: unsafe target directory: {$targetDir}\n");
    }
    if (!is_dir($path)) storageBenchmarkFail("Error: target not found: {$targetDir}\n");
    return $path;
}

/** Resolve and inspect only the device reported by df; callers may inject probes for hermetic tests. */
function storageBenchmarkHomeDeviceResolve(
    string $targetDir,
    ?string $mountDevice = null,
    string $sysfsRoot = '/sys/block',
    ?callable $deviceCheck = null,
    ?callable $canonicalPath = null,
    ?callable $sizeRead = null
): array {
    $path = $mountDevice ?? storageBenchmarkCommandFieldRead(
        'df -P '.escapeshellarg($targetDir).' | awk '.escapeshellarg('NR==2 {print $1}')
    );
    $entry = ['device' => $path, 'rota' => null, 'size' => null, 'mode' => 'home-device'];
    if ($path === null || !storageBenchmarkDevicePathIsSafe($path)) {
        return $entry + ['ok' => false, 'error' => 'unsafe or unavailable mount device'];
    }

    $check = $deviceCheck ?: static function (string $device): bool {
        return is_readable($device) && @filetype($device) === 'block';
    };
    if (!$check($path)) return $entry + ['ok' => false, 'error' => 'mount device is not a readable block device'];

    $resolve = $canonicalPath ?: 'realpath';
    $canonical = $resolve($path);
    if (!is_string($canonical) || !storageBenchmarkDevicePathIsSafe($canonical)) {
        return $entry + ['ok' => false, 'error' => 'unable to resolve mount device'];
    }
    $readSize = $sizeRead ?: 'storageBenchmarkDeviceSizeBytesRead';
    $size = $readSize($path);
    if (!is_int($size) || $size <= 0) return $entry + ['ok' => false, 'error' => 'unable to determine block device size'];

    $name = basename($canonical);
    $sysfsDevice = rtrim($sysfsRoot, '/').'/'.$name;
    $rotational = @file_get_contents($sysfsDevice.'/queue/rotational');
    $entry['rota'] = $rotational === false || !in_array(trim($rotational), ['0', '1'], true) ? null : (int) trim($rotational);
    $entry['size'] = $size;
    if (is_dir($sysfsDevice.'/md')) {
        $state = @file_get_contents($sysfsDevice.'/md/array_state');
        $degraded = @file_get_contents($sysfsDevice.'/md/degraded');
        if ($state === false || $degraded === false || !ctype_digit(trim($degraded))) {
            return $entry + ['ok' => false, 'error' => 'unable to read md array status'];
        }
        $entry['md'] = ['array_state' => trim($state), 'degraded' => (int) trim($degraded)];
    }
    return $entry + ['ok' => true];
}

/** Run the volume read jobs; injectable runners keep tests away from real devices. */
function storageBenchmarkHomeDeviceRunTests(
    string $jsonLog,
    array $base,
    array $device,
    int $ddSizeBytes,
    int $runtime,
    bool $fioPresent,
    ?callable $fioRunner = null,
    ?callable $ddRunner = null
): void {
    $row = $base + $device;
    unset($row['ok']);
    $path = $device['device'];
    if ($fioPresent) {
        $runFio = $fioRunner ?: 'fioRun';
        foreach ([
            ['name' => 'home-device-randread-4k', 'rw' => 'randread', 'bs' => '4k', 'iodepth' => 64],
            ['name' => 'home-device-seqread-1M', 'rw' => 'read', 'bs' => '1M', 'iodepth' => 32],
        ] as $job) {
            $job += ['numjobs' => 1, 'direct' => 1, 'readonly' => true];
            $result = $runFio($path, $device['size'], $runtime, $job);
            $entry = storageBenchmarkApplyRunResult($row + [
                'test' => $job['name'], 'params' => ['rw' => $job['rw'], 'bs' => $job['bs'],
                    'iodepth' => $job['iodepth'], 'numjobs' => 1, 'direct' => 1,
                    'readonly' => true, 'runtime' => $runtime], 'ok' => $result['ok'],
            ], $result, 'fio failed');
            storageBenchmarkAppendJsonLine($jsonLog, $entry);
            printf("%s\t%s\tread_MB/s=%s\tread_IOPS=%s\n", $path, $job['name'],
                $result['ok'] ? number_format($result['result']['read_bw_MBps'], 2) : 'n/a',
                $result['ok'] ? number_format($result['result']['read_iops'], 1) : 'n/a');
        }
        return;
    }

    storageBenchmarkAppendJsonLine($jsonLog, $row + ['test' => 'home-device-randread-4k',
        'ok' => false, 'measured' => false, 'error' => 'fio unavailable; random read not measured']);
    printf("%s\thome-device-randread-4k\tnot measured (fio unavailable)\n", $path);
    $count = (int) floor($ddSizeBytes / (1024 * 1024));
    $size = $device['size'];
    $skip = $size > ($count * 1024 * 1024 + 4 * 1024 * 1024)
        ? random_int(0, (int) floor(($size - $count * 1024 * 1024) / (1024 * 1024))) : 0;
    $runDd = $ddRunner ?: 'storageBenchmarkDdSeqread';
    $dd = $runDd($path, $count, $skip);
    $entry = $row + ['test' => 'home-device-seqread-dd', 'params' => ['bs' => '1M',
        'count' => $count, 'skip_blocks' => $skip], 'ok' => ($dd['rc'] === 0 && $dd['mbps'] !== null)];
    if ($dd['mbps'] !== null) $entry['metrics'] = ['seqread_MBps' => $dd['mbps'], 'elapsed_s' => $dd['secs']];
    else $entry['error'] = 'dd parse failed';
    storageBenchmarkAppendJsonLine($jsonLog, $entry);
    printf("%s\thome-device-seqread-dd\tdd_seqread_MB/s=%s\n", $path,
        $dd['mbps'] !== null ? number_format($dd['mbps'], 2) : 'n/a');
}

/** Keep the new mode isolated from file allocation and member-device inventory. */
function storageBenchmarkHomeDeviceMain(array $parsed, string $targetDir, string $jsonLog, string $label, string $ddSize, bool $requireIdle): int
{
    $runtime = storageBenchmarkRequireIntOption($parsed, 'device-runtime', 30, 1, 'positive');
    $idleLatencyMs = storageBenchmarkRequireIntOption($parsed, 'idle-latency-ms', 100, 0, 'non-negative');
    $idleUtilPct = storageBenchmarkRequireIntOption($parsed, 'idle-util', 85, 0, 'non-negative');
    storageBenchmarkRequireJsonLogPath($jsonLog);
    $targetDir = storageBenchmarkHomeDeviceRequireTarget($targetDir);
    $runId = date('YmdHis').'-'.bin2hex(random_bytes(3)); $runTs = date('c');
    $base = storageBenchmarkEntryBase($runTs, $label, $runId);
    $device = storageBenchmarkHomeDeviceResolve($targetDir);
    if (!$device['ok']) {
        storageBenchmarkAppendJsonLine($jsonLog, $base + $device + ['test' => 'home-device-preflight']);
        return pmssCliReturnWithStderr($device['error']."\n", 3);
    }

    $pre = $base + $device + ['target_dir' => $targetDir, 'test' => 'preflight-idle', 'ok' => true];
    $pre['ioping_avg_ms'] = pmssIopingAverageMs($targetDir);
    if (($pre['ioping_avg_ms'] ?? 0) > $idleLatencyMs) { $pre['ok'] = false; $pre['warn'] = 'ioping above threshold'; }
    $iostatUtilPct = storageBenchmarkIostatUtilPctRead('/var/run/pmss/iostat');
    if ($iostatUtilPct !== null) { $pre['iostat_util_pct'] = $iostatUtilPct; if ($pre['iostat_util_pct'] > $idleUtilPct) { $pre['ok'] = false; $pre['warn_util'] = 'iostat util high'; } }
    storageBenchmarkAppendJsonLine($jsonLog, $pre);
    if ($requireIdle && !$pre['ok']) return pmssCliReturnWithStderr("Busy system (--require-idle): aborting.\n", 2);

    $fioPresent = pmssCommandPath('fio') !== '';
    $ddSizeBytes = $fioPresent ? 0 : storageBenchmarkRequireSizeBytes('--dd-size', $ddSize, 1024 * 1024, '1 MiB');
    storageBenchmarkHomeDeviceRunTests($jsonLog, $base, $device, $ddSizeBytes, $runtime, $fioPresent);
    return 0;
}
