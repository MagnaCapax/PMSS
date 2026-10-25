<?php
/**
 * Shared execution helpers for the non-destructive storage benchmark CLI.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';
pmssRequireRelativeFiles(__DIR__, ['cli/optionParser.php', 'storageBenchmark/report.php', 'storageBenchmark/jobs.php', 'storageBenchmark/homeDeviceInspection.php', 'storageHealth/common.php']);
require_once __DIR__.'/storageBenchmarkHomeDevice.php';

/** Emit a legacy CLI fatal error and preserve the historical exit code. */
function storageBenchmarkFail(string $message): void { exit(pmssCliReturnWithStderr($message)); }
function storageBenchmarkRequireSizeBytes(string $optionName, string $value, int $minimumBytes = 1, string $minimumLabel = 'positive size'): int { $bytes = preg_match('/^([0-9]+)([KMGTP]i?B?)?$/i', trim($value)) === 1 ? pmssParseSizeToBytes($value, true, true) : null; if ($bytes === null || $bytes <= 0.0 || !is_finite($bytes) || $bytes >= (float) PHP_INT_MAX) storageBenchmarkFail("Error: {$optionName} must be a positive size (examples: 1G, 512M, 1048576).\n"); if ($bytes < $minimumBytes) storageBenchmarkFail("Error: {$optionName} must be at least {$minimumLabel}.\n"); return (int) $bytes; }
function storageBenchmarkRequireIntOption(array $parsed, string $optionName, int $default, int $minimum, string $minimumLabel): int { $value = pmssCliOption($parsed, $optionName, null, null); if ($value === null || $value === true) return $default; $parsedInt = is_string($value) ? pmssUnsignedDecimalIntParse($value) : null; if ($parsedInt === null || $parsedInt < $minimum) storageBenchmarkFail("Error: --{$optionName} must be a {$minimumLabel} integer.\n"); return $parsedInt; }
function storageBenchmarkRequireJsonLogPath(string $jsonLog): void { $jsonDir = dirname($jsonLog); $jsonDirError = null; if (!pmssLogWriteDirectoryPrepare($jsonDir, 0755, $jsonDirError, true)) storageBenchmarkFail($jsonDirError === 'create' ? "Error: failed to create JSON log directory: {$jsonDir}\n" : "Error: unsafe JSON log path: {$jsonLog}\n"); if (!pmssLogWritePathIsSafe($jsonLog)) storageBenchmarkFail("Error: unsafe JSON log path: {$jsonLog}\n"); }
/** File jobs require write access; mounted-device reads only require an existing directory. */
function storageBenchmarkRequireTargetDir(string $targetDir, bool $writable = true): string
{
    $path = rtrim($targetDir, '/');
    if ($path === '' || preg_match('/[\r\n\0]/', $path) === 1 || !pmssPathSegmentsAreSafe($path, true, true, true, true)) storageBenchmarkFail("Error: unsafe target directory: {$targetDir}\n");
    if (!is_dir($path) || ($writable && !is_writable($path))) storageBenchmarkFail('Error: target '.($writable ? 'not writable' : 'not found').": {$targetDir}\n");
    return $path;
}

function storageBenchmarkMain(array $argv): int
{
    $valueOptionNames = ['--target', '--size', '--json', '--label', '--dd-size', '--runtime', '--device-runtime', '--idle-latency-ms', '--idle-util'];
    $usage = "\nStorage benchmark (non-destructive)\n".pmssCliHelpUsageOptions('storageBenchmark.php [options]', [
        ['--target <dir>', 'Directory for file-backed tests (default /home).'], ['--size <bytes|MiB|GiB>', 'Target file size (default 500G, capped to 80% free).'],
        ['--runtime <seconds>', 'Per-test runtime for volume fio tests (default 60).'], ['--label <name>', 'Tag results (e.g., hostname/site/array).'],
        ['--json <path>', 'JSON Lines log (default /var/log/pmss/benchmark-storage.jsonl).'], ['--devices', 'Enable per-device tests (dd seqread + fio randread).'],
        ['--home-device', 'Read-only test of the block device under --target (default /home); skips file and per-member device tests.'],
        ['--dd-size <MiB|GiB>', 'Size for dd seqread per device (default 1G).'], ['--device-runtime <sec>', 'Per-device fio runtime (default 30).'],
        ['--require-idle', 'Abort if busy (ioping/iostat exceed thresholds).'], ['--idle-latency-ms <ms>', 'ioping avg latency threshold (default 100).'],
        ['--idle-util <percent>', 'iostat util threshold (default 85).'], ['--show-last', 'Print the last run human summary and exit.'],
    ], 28, ['Also accepts --key=value form for all value options.']);
    if (($parsed = pmssParseCliTokensOrHelp($argv, $usage, $valueOptionNames)) === null) return 0;

    $testDevices = pmssCliOptionPresent($parsed, 'devices', null, true); $homeDevice = pmssCliOptionPresent($parsed, 'home-device', null, true); $requireIdle = pmssCliOptionPresent($parsed, 'require-idle', null, true); $showLast = pmssCliOptionPresent($parsed, 'show-last', null, true);
    $targetDir = (string) pmssCliOptionString($parsed, 'target', null, '/home', true); $fileSize = (string) pmssCliOptionString($parsed, 'size', null, '500G', true); $jsonLog = (string) pmssCliOptionString($parsed, 'json', null, '/var/log/pmss/benchmark-storage.jsonl', true);
    $label = (string) pmssCliOptionString($parsed, 'label', null, '', true); $ddSize = (string) pmssCliOptionString($parsed, 'dd-size', null, '1G', true);
    if ($showLast) return storageBenchmarkShowLast($jsonLog);

    if ($homeDevice) return storageBenchmarkHomeDeviceMain($parsed, $targetDir, $jsonLog, $label, $ddSize, $requireIdle);

    $runtime = storageBenchmarkRequireIntOption($parsed, 'runtime', 60, 1, 'positive'); $devRuntime = $testDevices ? storageBenchmarkRequireIntOption($parsed, 'device-runtime', 30, 1, 'positive') : 30;
    $idleLatencyMs = storageBenchmarkRequireIntOption($parsed, 'idle-latency-ms', 100, 0, 'non-negative'); $idleUtilPct = storageBenchmarkRequireIntOption($parsed, 'idle-util', 85, 0, 'non-negative');
    $requested = storageBenchmarkRequireSizeBytes('--size', $fileSize); $ddSizeBytes = $testDevices ? storageBenchmarkRequireSizeBytes('--dd-size', $ddSize, 1024 * 1024, '1 MiB') : 0;
    storageBenchmarkRequireJsonLogPath($jsonLog); $targetDir = storageBenchmarkRequireTargetDir($targetDir);
    if (pmssCommandPath('fio') === '') return pmssCliReturnWithStderr("Error: 'fio' not found.\n");

    $runId = date('YmdHis').'-'.bin2hex(random_bytes(3)); $runTs = date('c');
    $fs = storageBenchmarkRequireCommandField('stat -f -c %T '.escapeshellarg($targetDir), 'filesystem type'); $mntDev = storageBenchmarkRequireCommandField('df -P '.escapeshellarg($targetDir).' | awk '.escapeshellarg('NR==2 {print $1}'), 'mount device');
    $idle = storageBenchmarkIdlePreflight($jsonLog, storageBenchmarkEntryBase($runTs, $label, $runId) + ['target_dir' => $targetDir], $targetDir, $idleLatencyMs, $idleUtilPct);
    if ($requireIdle && !$idle) return pmssCliReturnWithStderr("Busy system (--require-idle): aborting.\n", 2);

    $summary = storageBenchmarkRunFileTests($targetDir, $jsonLog, $runTs, $label, $runId, $mntDev, $fs, $requested, $runtime);
    storageBenchmarkPrintFileSummary($targetDir, $jsonLog, $label, $summary);
    if ($testDevices) storageBenchmarkRunDeviceTests($jsonLog, $runTs, $label, $runId, $ddSizeBytes, $devRuntime);
    return 0;
}

function storageBenchmarkAppendJsonLine(string $jsonLog, array $entry): void { if (!pmssJsonLineAppend($jsonLog, $entry)) storageBenchmarkFail("Error: failed to append JSON log entry: {$jsonLog}\n"); }
function storageBenchmarkEntryBase(string $runTs, string $label, string $runId): array { return ['timestamp' => $runTs, 'label' => $label ?: null, 'run_id' => $runId, 'run_ts' => $runTs]; }
function storageBenchmarkApplyRunResult(array $entry, array $res, string $fallbackError = 'unknown'): array { if ($res['ok']) $entry['metrics'] = $res['result']; else $entry['error'] = $res['error'] ?? $fallbackError; return $entry; }
function storageBenchmarkIostatUtilPctRead(string $path): ?float { $payload = pmssReadSerializedArrayFile($path); $util = $payload['diskUtil'] ?? null; return (is_int($util) || is_float($util) || (is_string($util) && is_numeric(trim($util)))) ? (float) trim((string) $util) : null; }
/** Persist the shared idle evidence before either benchmark mode decides whether to abort. */
function storageBenchmarkIdlePreflight(string $jsonLog, array $base, string $targetDir, int $latencyLimit, int $utilLimit): bool
{
    $pre = $base + ['test' => 'preflight-idle', 'ok' => true];
    $pre['ioping_avg_ms'] = pmssIopingAverageMs($targetDir);
    if (($pre['ioping_avg_ms'] ?? 0) > $latencyLimit) { $pre['ok'] = false; $pre['warn'] = 'ioping above threshold'; }
    $util = storageBenchmarkIostatUtilPctRead('/var/run/pmss/iostat'); if ($util !== null) { $pre['iostat_util_pct'] = $util; if ($util > $utilLimit) { $pre['ok'] = false; $pre['warn_util'] = 'iostat util high'; } }
    storageBenchmarkAppendJsonLine($jsonLog, $pre);
    return $pre['ok'];
}
/** Read a command field, rejecting malformed raw bytes before trimming. */
function storageBenchmarkCommandFieldRead(string $command, bool $positiveInt = false): ?string
{
    $result = pmssCommandCapture($command, 30);
    $raw = (string) ($result['stdout'] ?? '');
    $value = trim($raw);
    if ((int) ($result['rc'] ?? 1) !== 0 || pmssFilesystemPathHasNulByte($raw)
        || $value === '' || preg_match('/[\r\n\0]/', $value) === 1
        || ($positiveInt && (pmssUnsignedDecimalIntParse($value) ?? 0) <= 0)) {
        return null;
    }
    return $value;
}
function storageBenchmarkRequireCommandField(string $command, string $label, bool $positiveInt = false): string
{
    $value = storageBenchmarkCommandFieldRead($command, $positiveInt);
    if ($value === null) storageBenchmarkFail("Error: failed to read {$label}.\n");
    return $value;
}
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

/** Reserve the benchmark name before fio or fallocate can touch it. */
function storageBenchmarkFileReserve(string $path): ?array
{
    $handle = @fopen($path, 'x');
    if ($handle === false) return null;
    $identity = @fstat($handle);
    @fclose($handle);
    return is_array($identity) ? ['dev' => $identity['dev'], 'ino' => $identity['ino']] : null;
}

/** Never remove a path replaced after this run reserved its file. */
function storageBenchmarkFileCleanup(string $path, array $identity): bool
{
    clearstatcache(true, $path);
    $current = @lstat($path);
    return is_array($current) && pmssFilesystemStatSameInode($current, $identity) && @unlink($path);
}

/** Read block-device size through a checked command boundary. */
function storageBenchmarkDeviceSizeBytesRead(string $path): ?int
{
    if (!storageBenchmarkDevicePathIsSafe($path)) return null;
    $value = storageBenchmarkCommandFieldRead('blockdev --getsize64 '.escapeshellarg($path), true);
    return $value === null ? null : (int) $value;
}

function fioRun(string $file, int $size, int $runtime, array $job): array
{
    $json = pmssCreatePrivateTempFile('fio-'); if ($json === null) return ['ok' => false, 'error' => 'unable to allocate fio JSON temp file'];
    try {
        $opts = ['--name='.escapeshellarg($job['name']), '--filename='.escapeshellarg($file), '--size='.$size, '--time_based=1', '--runtime='.(int) ($job['runtime'] ?? $runtime), '--rw='.escapeshellarg($job['rw']), '--ioengine=libaio', '--iodepth='.(int) $job['iodepth'], '--numjobs='.(int) $job['numjobs'], '--direct='.(int) $job['direct'], '--group_reporting=1']; if (isset($job['bs'])) $opts[] = '--bs='.escapeshellarg($job['bs']); if (isset($job['bssplit'])) $opts[] = '--bssplit='.escapeshellarg($job['bssplit']); if (isset($job['rwmixread'])) $opts[] = '--rwmixread='.(int) $job['rwmixread']; if (!empty($job['readonly'])) $opts[] = '--readonly'; $cmd = 'fio --output-format=json --output '.escapeshellarg($json).' '.implode(' ', $opts); $rc = runCommand($cmd, true); $payload = @file_get_contents($json);
    } finally {
        // Release the private output even when quoting, execution, or reading throws.
        @unlink($json);
    }
    if ($rc !== 0 || $payload === false || trim($payload) === '') return ['ok' => false, 'error' => 'fio failed'];
    $j = pmssJsonDecodeAssoc($payload);
    if ($j === null || !is_array($j['jobs'] ?? null) || empty($j['jobs'][0])) {
        return ['ok' => false, 'error' => 'invalid fio JSON'];
    }
    $rbw = 0; $wbw = 0; $ri = 0.0; $wi = 0.0; $rp = []; $wp = [];
    foreach ($j['jobs'] as $jobj) {
        // A partial or malformed job cannot supply a successful measurement.
        if (!is_array($jobj)
            || (isset($jobj['read']) && !is_array($jobj['read']))
            || (isset($jobj['write']) && !is_array($jobj['write']))) {
            return ['ok' => false, 'error' => 'invalid fio JSON'];
        }
        // Check each job even after the command itself reported success.
        if (($jobj['error'] ?? 0) !== 0) return ['ok' => false, 'error' => 'fio failed'];
        // Do not turn malformed scalars or oversized counters into successful metrics.
        $bandwidth = $iops = [];
        foreach (['read', 'write'] as $direction) {
            $raw = $jobj[$direction]['bw_bytes'] ?? 0;
            $bandwidth[$direction] = is_int($raw) || is_string($raw) ? pmssUnsignedDecimalIntParse((string) $raw) : null;
            $iops[$direction] = pmssFiniteNonNegativeFloat($jobj[$direction]['iops'] ?? 0);
            if ($bandwidth[$direction] === null || $iops[$direction] === null) {
                return ['ok' => false, 'error' => 'invalid fio JSON'];
            }
        }
        if ($rbw > PHP_INT_MAX - $bandwidth['read'] || $wbw > PHP_INT_MAX - $bandwidth['write']) {
            return ['ok' => false, 'error' => 'invalid fio JSON'];
        }
        $rbw += $bandwidth['read']; $wbw += $bandwidth['write'];
        $ri += $iops['read']; $wi += $iops['write'];
        $p95r = $jobj['read']['clat_ns']['percentile']['95.000000'] ?? null;
        $p95w = $jobj['write']['clat_ns']['percentile']['95.000000'] ?? null;
        foreach ([$p95r, $p95w] as $percentile) {
            if ($percentile !== null && pmssFiniteNonNegativeFloat($percentile) === null) {
                return ['ok' => false, 'error' => 'invalid fio JSON'];
            }
        }
        if ($p95r !== null) $rp[] = (float) $p95r;
        if ($p95w !== null) $wp[] = (float) $p95w;
    }
    // Individually finite samples can still overflow during aggregation.
    if (!is_finite($ri) || !is_finite($wi) || !is_finite((float) array_sum($rp)) || !is_finite((float) array_sum($wp))) {
        return ['ok' => false, 'error' => 'invalid fio JSON'];
    }
    $avg = static function ($a) { return count($a) ? array_sum($a) / count($a) : 0; };
    return ['ok' => true, 'result' => ['read_bw_MBps' => round($rbw / (1024 * 1024), 2), 'write_bw_MBps' => round($wbw / (1024 * 1024), 2), 'read_iops' => round($ri, 1), 'write_iops' => round($wi, 1), 'read_p95_ms' => round($avg($rp) / 1000000, 2), 'write_p95_ms' => round($avg($wp) / 1000000, 2), 'raw' => $j]];
}

function storageBenchmarkRunFileTests(string $targetDir, string $jsonLog, string $runTs, string $label, string $runId, string $mntDev, string $fs, int $requested, int $runtime): array
{
    $free = (int) storageBenchmarkRequireCommandField('df -PB1 '.escapeshellarg($targetDir).' | awk '.escapeshellarg('NR==2 {print $4}'), 'free space', true);
    $use = (int) min($requested, floor($free * 0.8));
    if ($use <= 0) storageBenchmarkFail("Insufficient free space.\n");
    $testFile = rtrim($targetDir, '/').'/pmss-fio-'.bin2hex(random_bytes(4)).'.dat';
    $testFileIdentity = storageBenchmarkFileReserve($testFile);
    if ($testFileIdentity === null) storageBenchmarkFail("Error: failed to reserve benchmark file.\n");
    register_shutdown_function(static function () use ($testFile, $testFileIdentity): void {
        storageBenchmarkFileCleanup($testFile, $testFileIdentity);
    });
    if (pmssCommandPath('fallocate') !== '' && runCommand('fallocate -l '.$use.' '.escapeshellarg($testFile)) !== 0) storageBenchmarkFail("Error: failed to preallocate benchmark file.\n");
    $summary = [];
    $base = storageBenchmarkEntryBase($runTs, $label, $runId) + ['target_dir' => $targetDir, 'device' => $mntDev, 'filesystem' => $fs];
    $params = array_fill_keys(['rw', 'rwmixread', 'bs', 'bssplit', 'iodepth', 'numjobs', 'direct', 'runtime'], null) + ['size_bytes' => $use];
    foreach (storageBenchmarkFioJobsRun($jsonLog, $testFile, $use, $runtime, storageBenchmarkFileJobs($runtime), $base, $params) as $name => $res) {
        if ($res['ok']) $summary[] = [$name, $res['result']['read_bw_MBps'], $res['result']['write_bw_MBps'], $res['result']['read_iops'], $res['result']['write_iops'], $res['result']['read_p95_ms'], $res['result']['write_p95_ms']];
    }
    storageBenchmarkFileCleanup($testFile, $testFileIdentity);
    return $summary;
}

function storageBenchmarkDevicePreflightSkip(string $jsonLog, array $deviceEntry, string $error): void { storageBenchmarkAppendJsonLine($jsonLog, $deviceEntry + ['test' => 'device-preflight', 'ok' => false, 'error' => $error]); printf("%s\tskipped: %s\n", $deviceEntry['device'], $error); }
function storageBenchmarkDdSeqread(string $path, int $count, int $skip): array
{
    $dd = sprintf('dd if=%s of=/dev/null bs=1M count=%d skip=%d iflag=direct 2>&1', escapeshellarg($path), $count, $skip);
    [$rc, $so, $se] = [runCommand($dd, true), $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '', $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stderr'] ?? ''];
    $line = trim($se !== '' ? $se : $so);
    $mbps = $secs = null;
    // A failed read can leave a partial transfer summary; it is not a measurement.
    if ($rc === 0 && preg_match('/\s([0-9.]+)\s+s,\s+([0-9.]+)\s+MB\/s/', $line, $match)) {
        $elapsed = pmssFiniteNonNegativeFloat($match[1]);
        $rate = pmssFiniteNonNegativeFloat($match[2]);
        if ($elapsed !== null && $rate !== null) {
            $secs = $elapsed;
            $mbps = $rate;
        }
    }
    return ['rc' => $rc, 'mbps' => $mbps, 'secs' => $secs];
}
/** Preserve the JSON shape for dd results in both read-only device modes. */
function storageBenchmarkDdResultEntry(array $deviceEntry, string $test, int $count, int $skip, array $dd): array
{
    return array_replace($deviceEntry + ['test' => $test, 'params' => ['bs' => '1M', 'count' => $count, 'skip_blocks' => $skip], 'ok' => ($dd['rc'] === 0 && $dd['mbps'] !== null)],
        $dd['mbps'] !== null ? ['metrics' => ['seqread_MBps' => $dd['mbps'], 'elapsed_s' => $dd['secs']]] : ['error' => 'dd parse failed']);
}
/** Choose an in-bounds MiB offset with the existing four-MiB tail margin. */
function storageBenchmarkDdSkipBlocks(int $size, int $count): int { return $size > ($count * 1024 * 1024 + 4 * 1024 * 1024) ? random_int(0, (int) floor(($size - $count * 1024 * 1024) / (1024 * 1024))) : 0; }
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
        $params = array_fill_keys(['rw', 'bs', 'iodepth', 'numjobs', 'runtime'], null);
        foreach (storageBenchmarkFioJobsRun($jsonLog, $path, $size, $devRuntime, storageBenchmarkDeviceJobs(), $deviceEntry, $params, 'fio failed') as $name => $res) {
            if ($res['ok']) printf("%s\t%s\tread_MB/s=%.2f\tread_IOPS=%.1f\tread_p95_ms=%.2f\n", $path, $name, $res['result']['read_bw_MBps'], $res['result']['read_iops'], $res['result']['read_p95_ms']);
            if ($res['ok'] && $name === 'dev-randread-4k') $peer[$path]['fio4k_mb'] = $res['result']['read_bw_MBps'];
        }
        $peer[$path]['dd_mb'] = $dd['mbps']; $peer[$path]['iop_ms'] = $iop;
    }
    storageBenchmarkPrintPeerWarnings($peer);
}
