<?php
/** Read-only member-device probes and result publication. @license GPL-3.0-only */

/** Return true only for raw block-device paths safe to pass to read-only probes. */
function storageBenchmarkDevicePathIsSafe(string $path): bool
{
    return strpos($path, '/dev/') === 0
        && preg_match('/[\r\n\0]/', $path) !== 1
        && pmssPathSegmentsAreSafe($path, false, false);
}

/** Both device modes require the same readable block-device boundary. */
function storageBenchmarkDeviceIsReadableBlock(string $path): bool
{ return storageBenchmarkDevicePathIsSafe($path) && is_readable($path) && @filetype($path) === 'block'; }

/** Read block-device size through a checked command boundary. */
function storageBenchmarkDeviceSizeBytesRead(string $path): ?int
{
    if (!storageBenchmarkDevicePathIsSafe($path)) return null;
    $value = storageBenchmarkCommandFieldRead('blockdev --getsize64 '.escapeshellarg($path), true);
    return $value === null ? null : (int) $value;
}

function storageBenchmarkDevicePreflightSkip(string $jsonLog, array $deviceEntry, string $error): void { storageBenchmarkAppendJsonLine($jsonLog, $deviceEntry + ['test' => 'device-preflight', 'ok' => false, 'error' => $error]); printf("%s\tskipped: %s\n", $deviceEntry['device'], $error); }
function storageBenchmarkDdSeqread(string $path, int $count, int $skip): array
{
    [$rc, $so, $se] = [runCommand(sprintf('dd if=%s of=/dev/null bs=1M count=%d skip=%d iflag=direct 2>&1', escapeshellarg($path), $count, $skip), true), $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '', $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stderr'] ?? ''];
    $raw = $se !== '' ? $se : $so;
    $line = trim($raw);
    // A failed read can leave a partial transfer summary; it is not a measurement.
    // NUL-containing output must not match a valid summary embedded within it.
    if ($rc === 0 && !pmssFilesystemPathHasNulByte($raw)
        && preg_match('/\s([0-9.]+)\s+s,\s+([0-9.]+)\s+MB\/s/', $line, $match)) {
        $secs = pmssFiniteNonNegativeFloat($match[1]);
        $mbps = pmssFiniteNonNegativeFloat($match[2]);
        if ($secs !== null && $mbps !== null) return ['rc' => $rc, 'mbps' => $mbps, 'secs' => $secs];
    }
    return ['rc' => $rc, 'mbps' => null, 'secs' => null];
}
/** Preserve the JSON shape for dd results in both read-only device modes. */
function storageBenchmarkDdResultEntry(array $deviceEntry, string $test, int $count, int $skip, array $dd): array
{
    return array_replace($deviceEntry + ['test' => $test, 'params' => ['bs' => '1M', 'count' => $count, 'skip_blocks' => $skip], 'ok' => ($dd['rc'] === 0 && $dd['mbps'] !== null)],
        $dd['mbps'] !== null ? ['metrics' => ['seqread_MBps' => $dd['mbps'], 'elapsed_s' => $dd['secs']]] : ['error' => 'dd parse failed']);
}
function storageBenchmarkRunDeviceTests(string $jsonLog, string $runTs, string $label, string $runId, int $ddSizeBytes, int $devRuntime): void
{
    echo "\n== Per-device read-only benchmarks ==\n";
    $base = storageBenchmarkEntryBase($runTs, $label, $runId);
    $peer = [];
    foreach (pmssStorageHealthDiskInventoryRead() as $meta) {
        $path = $meta['path'];
        $deviceEntry = $base + ['device' => $path, 'model' => $meta['model'], 'serial' => $meta['serial'], 'rota' => $meta['rota'], 'size' => $meta['size']];
        if (!storageBenchmarkDeviceIsReadableBlock($path)) { storageBenchmarkDevicePreflightSkip($jsonLog, $deviceEntry, 'not a readable block device'); continue; }
        $size = storageBenchmarkDeviceSizeBytesRead($path);
        if ($size === null) { storageBenchmarkDevicePreflightSkip($jsonLog, $deviceEntry, 'unable to determine block device size'); continue; }
        $dd = storageBenchmarkDdTestRun($jsonLog, $path, $deviceEntry, 'device-seqread-dd', $size, $ddSizeBytes, "%s\tdd_seqread_MB/s=%s\n");
        $iop = pmssIopingAverageMs($path);
        storageBenchmarkAppendJsonLine($jsonLog, $deviceEntry + ['test' => 'device-ioping', 'ok' => ($iop !== null), 'metrics' => ['ioping_avg_ms' => round($iop ?? 0, 2)]]);
        if ($iop !== null) printf("%s\tioping_avg_ms=%.2f\n", $path, $iop);
        foreach (storageBenchmarkFioJobsRun($jsonLog, $path, $size, $devRuntime, storageBenchmarkDeviceJobs(), $deviceEntry,
            array_fill_keys(['rw', 'bs', 'iodepth', 'numjobs', 'runtime'], null), 'fio failed') as $name => $res) {
            if (!$res['ok']) continue;
            printf("%s\t%s\tread_MB/s=%.2f\tread_IOPS=%.1f\tread_p95_ms=%.2f\n", $path, $name, $res['result']['read_bw_MBps'], $res['result']['read_iops'], $res['result']['read_p95_ms']);
            if ($name === 'dev-randread-4k') $peer[$path]['fio4k_mb'] = $res['result']['read_bw_MBps'];
        }
        $peer[$path]['dd_mb'] = $dd['mbps']; $peer[$path]['iop_ms'] = $iop;
    }
    storageBenchmarkPrintPeerWarnings($peer);
}
