<?php
/** Read-only benchmark of the block device mounted below the selected target. */

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

    $check = $deviceCheck ?: 'storageBenchmarkDeviceIsReadableBlock';
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
        $level = @file_get_contents($sysfsDevice.'/md/level');
        $degradedPath = $sysfsDevice.'/md/degraded';
        $degraded = @file_get_contents($degradedPath);
        if ($degraded === false && !file_exists($degradedPath) && $level !== false
            && in_array(trim($level), ['raid0', 'linear'], true)) {
            $degraded = '0';
        }
        if ($state === false || $level === false || $degraded === false || !ctype_digit(trim($degraded))) {
            return $entry + ['ok' => false, 'error' => 'unable to read md array status'];
        }
        $entry['md'] = ['array_state' => trim($state), 'degraded' => (int) trim($degraded), 'level' => trim($level)];
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
    $skip = storageBenchmarkDdSkipBlocks($device['size'], $count);
    $runDd = $ddRunner ?: 'storageBenchmarkDdSeqread';
    $dd = $runDd($path, $count, $skip);
    $entry = storageBenchmarkDdResultEntry($row, 'home-device-seqread-dd', $count, $skip, $dd);
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
    $targetDir = storageBenchmarkRequireTargetDir($targetDir, false);
    $runId = date('YmdHis').'-'.bin2hex(random_bytes(3)); $runTs = date('c');
    $base = storageBenchmarkEntryBase($runTs, $label, $runId);
    $device = storageBenchmarkHomeDeviceResolve($targetDir);
    if (!$device['ok']) {
        storageBenchmarkAppendJsonLine($jsonLog, $base + $device + ['test' => 'home-device-preflight']);
        return pmssCliReturnWithStderr($device['error']."\n", 3);
    }

    $idle = storageBenchmarkIdlePreflight($jsonLog, $base + $device + ['target_dir' => $targetDir], $targetDir, $idleLatencyMs, $idleUtilPct);
    if ($requireIdle && !$idle) return pmssCliReturnWithStderr("Busy system (--require-idle): aborting.\n", 2);

    $fioPresent = pmssCommandPath('fio') !== '';
    $ddSizeBytes = $fioPresent ? 0 : storageBenchmarkRequireSizeBytes('--dd-size', $ddSize, 1024 * 1024, '1 MiB');
    storageBenchmarkHomeDeviceRunTests($jsonLog, $base, $device, $ddSizeBytes, $runtime, $fioPresent);
    return 0;
}
