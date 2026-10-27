<?php
/** Read-only benchmark of the block device mounted below the selected target. */

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
    $row = array_diff_key($base + $device, ['ok' => true]);
    $path = $device['device'];
    if ($fioPresent) {
        foreach (storageBenchmarkFioJobsRun($jsonLog, $path, $device['size'], $runtime, PMSS_STORAGE_BENCHMARK_HOME_DEVICE_JOBS, $row,
            array_fill_keys(['rw', 'bs', 'iodepth', 'numjobs', 'direct', 'readonly', 'runtime'], null), 'fio failed', $fioRunner) as $name => $result) {
            printf("%s\t%s\tread_MB/s=%s\tread_IOPS=%s\n", $path, $name,
                $result['ok'] ? number_format($result['result']['read_bw_MBps'], 2) : 'n/a',
                $result['ok'] ? number_format($result['result']['read_iops'], 1) : 'n/a');
        }
        return;
    }

    storageBenchmarkAppendJsonLine($jsonLog, $row + ['test' => 'home-device-randread-4k',
        'ok' => false, 'measured' => false, 'error' => 'fio unavailable; random read not measured']);
    printf("%s\thome-device-randread-4k\tnot measured (fio unavailable)\n", $path);
    storageBenchmarkDdTestRun($jsonLog, $path, $row, 'home-device-seqread-dd', $device['size'], $ddSizeBytes,
        "%s\thome-device-seqread-dd\tdd_seqread_MB/s=%s\n", $ddRunner);
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
    storageBenchmarkHomeDeviceRunTests($jsonLog, $base, $device,
        $fioPresent ? 0 : storageBenchmarkRequireSizeBytes('--dd-size', $ddSize, 1024 * 1024, '1 MiB'), $runtime, $fioPresent);
    return 0;
}
