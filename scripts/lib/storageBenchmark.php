<?php
/**
 * Shared execution helpers for the non-destructive storage benchmark CLI.
 *
 * The util wrapper owns argument parsing and help text; this file owns the
 * benchmark validation, JSONL entry construction, fio execution, and summaries.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/cli/optionParser.php';
require_once __DIR__.'/storageHealth/common.php';

function storageBenchmarkRequirePositiveSizeBytes(string $optionName, string $value): int { $bytes = preg_match('/^([0-9]+)([KMGTP]i?B?)?$/i', trim($value)) === 1 ? pmssParseSizeToBytes($value, true, true) : null; if ($bytes === null || $bytes <= 0.0) { fwrite(STDERR, "Error: {$optionName} must be a positive size (examples: 1G, 512M, 1048576).\n"); exit(1); } return (int) $bytes; }
function storageBenchmarkRequireMinimumSizeBytes(string $optionName, int $bytes, int $minimumBytes, string $minimumLabel): int { if ($bytes < $minimumBytes) { fwrite(STDERR, "Error: {$optionName} must be at least {$minimumLabel}.\n"); exit(1); } return $bytes; }
function storageBenchmarkRequireIntOption(array $parsed, string $optionName, int $default, int $minimum, string $minimumLabel): int { $value = pmssCliOption($parsed, $optionName, null, null); if ($value === null || $value === true) return $default; if (!is_string($value) || !ctype_digit($value) || (int) $value < $minimum) { fwrite(STDERR, "Error: --{$optionName} must be a {$minimumLabel} integer.\n"); exit(1); } return (int) $value; }
function storageBenchmarkRequireJsonLogPath(string $jsonLog): void { $jsonDir = dirname($jsonLog); $jsonDirError = null; if (!pmssLogWriteDirectoryPrepare($jsonDir, 0755, $jsonDirError, true)) { fwrite(STDERR, $jsonDirError === 'create' ? "Error: failed to create JSON log directory: {$jsonDir}\n" : "Error: unsafe JSON log path: {$jsonLog}\n"); exit(1); } if (!pmssLogWritePathIsSafe($jsonLog)) { fwrite(STDERR, "Error: unsafe JSON log path: {$jsonLog}\n"); exit(1); } }
function storageBenchmarkRequireTargetDir(string $targetDir): string { $path = rtrim($targetDir, '/'); if ($path === '' || preg_match('/[\r\n\0]/', $path) === 1 || !pmssPathSegmentsAreSafe($path, true, true, true, true)) { fwrite(STDERR, "Error: unsafe target directory: {$targetDir}\n"); exit(1); } if (!is_dir($path) || !is_writable($path)) { fwrite(STDERR, "Error: target not writable: {$targetDir}\n"); exit(1); } return $path; }

function storageBenchmarkAppendJsonLine(string $jsonLog, array $entry): void { if (!pmssJsonLineAppend($jsonLog, $entry)) { fwrite(STDERR, "Error: failed to append JSON log entry: {$jsonLog}\n"); exit(1); } }
function storageBenchmarkEntryBase(string $runTs, string $label, string $runId): array { return ['timestamp' => $runTs, 'label' => $label ?: null, 'run_id' => $runId, 'run_ts' => $runTs]; }
function storageBenchmarkIostatUtilPctRead(string $path): ?float { $payload = pmssReadSerializedArrayFile($path); if ($payload === null || !array_key_exists('diskUtil', $payload)) return null; $util = $payload['diskUtil']; return (is_int($util) || is_float($util) || (is_string($util) && is_numeric(trim($util)))) ? (float) trim((string) $util) : null; }
function storageBenchmarkRequireCommandField(string $command, string $label): string { $result = pmssCommandCapture($command, 30); $value = trim((string) ($result['stdout'] ?? '')); if ((int) ($result['rc'] ?? 1) !== 0 || $value === '' || preg_match('/[\r\n\0]/', $value) === 1) { fwrite(STDERR, "Error: failed to read {$label}.\n"); exit(1); } return $value; }
function storageBenchmarkRequirePositiveIntCommandField(string $command, string $label): int { $value = storageBenchmarkRequireCommandField($command, $label); if (!ctype_digit($value) || (int) $value <= 0) { fwrite(STDERR, "Error: failed to read {$label}.\n"); exit(1); } return (int) $value; }
function storageBenchmarkDeviceIsReadableBlock(string $path): bool { return strpos($path, '/dev/') === 0 && strpos($path, "\0") === false && !is_link($path) && is_readable($path) && @filetype($path) === 'block'; }
function storageBenchmarkRegisterFileCleanup(string $path): void { register_shutdown_function(static function () use ($path): void { if ($path !== '' && is_file($path)) @unlink($path); }); }

function storageBenchmarkShowLast(string $jsonLog): int { if (!is_file($jsonLog)) { fwrite(STDERR, "No log at {$jsonLog}\n"); return 1; } $runs = []; $lastId = ''; $lastTs = ''; foreach (pmssJsonLineFileRead($jsonLog) as $entry) { if (!isset($entry['run_id']) || !is_string($entry['run_id']) || $entry['run_id'] === '') continue; $runId = $entry['run_id']; $runs[$runId][] = $entry; $runTs = (isset($entry['run_ts']) && is_string($entry['run_ts'])) ? $entry['run_ts'] : ''; if ($runTs > $lastTs) { $lastTs = $runTs; $lastId = $runId; } } if ($lastId === '') { echo "No runs found.\n"; return 0; } $run = $runs[$lastId]; $first = $run[0]; $labelStr = (isset($first['label']) && $first['label'] !== '') ? ('  Label: '.$first['label']) : ''; echo "\n== Storage benchmark (last run) ==\nRun ID: {$lastId}  Time: ".($first['run_ts'] ?? '').$labelStr."\n\n"; foreach ($run as $entry) { if (($entry['test'] ?? '') === 'preflight-idle') { echo "Preflight: ioping=".($entry['ioping_avg_ms'] ?? 'n/a')." ms util=".($entry['iostat_util_pct'] ?? 'n/a')."%\n\n"; break; } } echo "File-backed tests\n"; echo "test\tread_MB/s\twrite_MB/s\tread_IOPS\twrite_IOPS\tread_p95\twrite_p95\n"; foreach ($run as $entry) { if (isset($entry['test']) && empty($entry['device']) && (($entry['params']['rw'] ?? '') !== '')) { $metrics = $entry['metrics'] ?? []; printf("%s\t%.2f\t%.2f\t%.1f\t%.1f\t%.2f\t%.2f\n", $entry['test'], $metrics['read_bw_MBps'] ?? 0, $metrics['write_bw_MBps'] ?? 0, $metrics['read_iops'] ?? 0, $metrics['write_iops'] ?? 0, $metrics['read_p95_ms'] ?? 0, $metrics['write_p95_ms'] ?? 0); } } echo "\nPer-device tests\n"; $devices = []; foreach ($run as $entry) { if (isset($entry['device'])) $devices[$entry['device']][] = $entry; } foreach ($devices as $device => $entries) { echo $device."\n"; foreach ($entries as $entry) { $test = $entry['test']; $metrics = $entry['metrics'] ?? []; if ($test === 'device-seqread-dd') printf("  %-18s seq_MB/s=%.2f t=%.2fs\n", $test, $metrics['seqread_MBps'] ?? 0, $metrics['elapsed_s'] ?? 0); elseif (strpos($test, 'dev-randread') === 0) printf("  %-18s read_MB/s=%.2f IOPS=%.1f p95=%.2fms\n", $test, $metrics['read_bw_MBps'] ?? 0, $metrics['read_iops'] ?? 0, $metrics['read_p95_ms'] ?? 0); elseif ($test === 'device-ioping') printf("  %-18s avg_ms=%.2f\n", $test, $metrics['ioping_avg_ms'] ?? 0); } } return 0; }

function fioRun(string $file, int $size, int $runtime, array $job): array { $json = pmssCreatePrivateTempFile('fio-'); if ($json === null) return ['ok' => false, 'error' => 'unable to allocate fio JSON temp file']; $opts = ['--name='.escapeshellarg($job['name']), '--filename='.escapeshellarg($file), '--size='.$size, '--time_based=1', '--runtime='.(int) ($job['runtime'] ?? $runtime), '--rw='.escapeshellarg($job['rw']), '--ioengine=libaio', '--iodepth='.(int) $job['iodepth'], '--numjobs='.(int) $job['numjobs'], '--direct='.(int) $job['direct'], '--group_reporting=1']; if (isset($job['bs'])) $opts[] = '--bs='.escapeshellarg($job['bs']); if (isset($job['bssplit'])) $opts[] = '--bssplit='.escapeshellarg($job['bssplit']); if (isset($job['rwmixread'])) $opts[] = '--rwmixread='.(int) $job['rwmixread']; $cmd = 'fio --output-format=json --output '.escapeshellarg($json).' '.implode(' ', $opts); $rc = runCommand($cmd, true); $payload = @file_get_contents($json); @unlink($json); if ($rc !== 0 || $payload === false || trim($payload) === '') return ['ok' => false, 'error' => 'fio failed']; $j = json_decode($payload, true); if (!is_array($j) || empty($j['jobs'][0])) return ['ok' => false, 'error' => 'invalid fio JSON']; $rbw = 0; $wbw = 0; $ri = 0.0; $wi = 0.0; $rp = []; $wp = []; foreach ($j['jobs'] as $jobj) { $rbw += (int) ($jobj['read']['bw_bytes'] ?? 0); $wbw += (int) ($jobj['write']['bw_bytes'] ?? 0); $ri += (float) ($jobj['read']['iops'] ?? 0); $wi += (float) ($jobj['write']['iops'] ?? 0); $p95r = $jobj['read']['clat_ns']['percentile']['95.000000'] ?? null; $p95w = $jobj['write']['clat_ns']['percentile']['95.000000'] ?? null; if ($p95r !== null) $rp[] = (float) $p95r; if ($p95w !== null) $wp[] = (float) $p95w; } $avg = static function ($a) { return count($a) ? array_sum($a) / count($a) : 0; }; return ['ok' => true, 'result' => ['read_bw_MBps' => round($rbw / (1024 * 1024), 2), 'write_bw_MBps' => round($wbw / (1024 * 1024), 2), 'read_iops' => round($ri, 1), 'write_iops' => round($wi, 1), 'read_p95_ms' => round($avg($rp) / 1000000, 2), 'write_p95_ms' => round($avg($wp) / 1000000, 2), 'raw' => $j]]; }

function storageBenchmarkFileJobs(): array { return [['name' => 'randmix-large-95r5w', 'rw' => 'randrw', 'rwmixread' => 95, 'bssplit' => '4k/2:64k/3:128k/5:256k/10:512k/20:768k/25:1024k/35', 'iodepth' => 32, 'numjobs' => 4, 'direct' => 1], ['name' => 'randread-large', 'rw' => 'randread', 'bs' => '1M', 'iodepth' => 32, 'numjobs' => 4, 'direct' => 1], ['name' => 'randread-small', 'rw' => 'randread', 'bs' => '4k', 'iodepth' => 64, 'numjobs' => 4, 'direct' => 1], ['name' => 'randwrite-small-short', 'rw' => 'randwrite', 'bs' => '4k', 'iodepth' => 32, 'numjobs' => 2, 'direct' => 1], ['name' => 'seqread-large', 'rw' => 'read', 'bs' => '1M', 'iodepth' => 32, 'numjobs' => 2, 'direct' => 1]]; }
function storageBenchmarkDeviceJobs(): array { return [['name' => 'dev-randread-4k', 'rw' => 'randread', 'bs' => '4k', 'iodepth' => 64], ['name' => 'dev-randread-1M', 'rw' => 'randread', 'bs' => '1M', 'iodepth' => 32]]; }

function storageBenchmarkFileEntryBuild(array $base, string $targetDir, string $mntDev, string $fs, array $job, int $runtime, int $use, array $res): array { $entry = $base + ['target_dir' => $targetDir, 'device' => $mntDev, 'filesystem' => $fs, 'test' => $job['name'], 'params' => ['rw' => $job['rw'], 'rwmixread' => $job['rwmixread'] ?? null, 'bs' => $job['bs'] ?? null, 'bssplit' => $job['bssplit'] ?? null, 'iodepth' => $job['iodepth'], 'numjobs' => $job['numjobs'], 'direct' => $job['direct'], 'runtime' => (int) ($job['runtime'] ?? $runtime), 'size_bytes' => $use], 'ok' => $res['ok']]; if ($res['ok']) $entry['metrics'] = $res['result']; else $entry['error'] = $res['error'] ?? 'unknown'; return $entry; }

function storageBenchmarkRunFileTests(string $targetDir, string $jsonLog, string $runTs, string $label, string $runId, string $mntDev, string $fs, int $requested, int $runtime): array
{
    $free = storageBenchmarkRequirePositiveIntCommandField('df -PB1 '.escapeshellarg($targetDir).' | awk '.escapeshellarg('NR==2 {print $4}'), 'free space');
    $use = (int) min($requested, floor($free * 0.8));
    if ($use <= 0) { fwrite(STDERR, "Insufficient free space.\n"); exit(1); }
    $testFile = rtrim($targetDir, '/').'/pmss-fio-'.bin2hex(random_bytes(4)).'.dat'; storageBenchmarkRegisterFileCleanup($testFile);
    if (pmssCommandPath('fallocate') !== '') runCommand('fallocate -l '.$use.' '.escapeshellarg($testFile));
    $summary = [];
    foreach (storageBenchmarkFileJobs() as $job) {
        if ($job['name'] === 'randwrite-small-short') $job['runtime'] = max(15, (int) floor($runtime / 3));
        $res = fioRun($testFile, $use, $runtime, $job);
        $entry = storageBenchmarkFileEntryBuild(storageBenchmarkEntryBase($runTs, $label, $runId), $targetDir, $mntDev, $fs, $job, $runtime, $use, $res);
        if ($res['ok']) $summary[] = [$job['name'], $res['result']['read_bw_MBps'], $res['result']['write_bw_MBps'], $res['result']['read_iops'], $res['result']['write_iops'], $res['result']['read_p95_ms'], $res['result']['write_p95_ms']];
        storageBenchmarkAppendJsonLine($jsonLog, $entry);
    }
    @unlink($testFile);
    return $summary;
}

function storageBenchmarkPrintFileSummary(string $targetDir, string $jsonLog, string $label, array $summary): void { echo "\n== Storage benchmark summary ".($label !== '' ? '(' . $label . ' on '.$targetDir.')' : "(on {$targetDir})")." ==\n"; echo "test\tread_MB/s\twrite_MB/s\tread_IOPS\twrite_IOPS\tread_p95_ms\twrite_p95_ms\n"; foreach ($summary as $row) printf("%s\t%.2f\t%.2f\t%.1f\t%.1f\t%.2f\t%.2f\n", ...$row); echo "\nJSON log: {$jsonLog}\n"; }
function storageBenchmarkDeviceEntryBuild(array $base, array $meta, string $path, string $test, array $extra = []): array { return $base + ['device' => $path, 'model' => $meta['model'], 'serial' => $meta['serial'], 'rota' => $meta['rota'], 'size' => $meta['size'], 'test' => $test] + $extra; }
function storageBenchmarkDdSeqread(string $path, int $count, int $skip): array { $dd = sprintf('dd if=%s of=/dev/null bs=1M count=%d skip=%d iflag=direct 2>&1', escapeshellarg($path), $count, $skip); [$rc, $so, $se] = [runCommand($dd, true), $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '', $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stderr'] ?? '']; $line = trim($se !== '' ? $se : $so); $mbps = null; $secs = null; if (preg_match('/\s([0-9.]+)\s+s,\s+([0-9.]+)\s+MB\/s/', $line, $m)) { $secs = (float) $m[1]; $mbps = (float) $m[2]; } return ['rc' => $rc, 'mbps' => $mbps, 'secs' => $secs]; }
function storageBenchmarkMetricValues(array $peer, string $key): array { $arr = []; foreach ($peer as $v) if (isset($v[$key]) && $v[$key] !== null) $arr[] = $v[$key]; return $arr; }
function storageBenchmarkMedian(array $values): float { sort($values); $n = count($values); if ($n === 0) return 0.0; $m = (int) floor(($n - 1) / 2); return $n % 2 ? (float) $values[$m] : (($values[$m] + $values[$m + 1]) / 2); }

function storageBenchmarkPrintPeerWarnings(array $peer): void
{
    $medDd = storageBenchmarkMedian(storageBenchmarkMetricValues($peer, 'dd_mb')); $medIop = storageBenchmarkMedian(storageBenchmarkMetricValues($peer, 'iop_ms')); $med4k = storageBenchmarkMedian(storageBenchmarkMetricValues($peer, 'fio4k_mb'));
    foreach ($peer as $p => $r) { if ($medDd > 0 && isset($r['dd_mb']) && $r['dd_mb'] !== null && $r['dd_mb'] < 0.6 * $medDd) echo "WARN: {$p} seqread < 60% median\n"; if ($medIop > 0 && isset($r['iop_ms']) && $r['iop_ms'] !== null && $r['iop_ms'] > max(50, 2 * $medIop)) echo "WARN: {$p} ioping > 2x median\n"; if ($med4k > 0 && isset($r['fio4k_mb']) && $r['fio4k_mb'] !== null && $r['fio4k_mb'] < 0.5 * $med4k) echo "WARN: {$p} 4k randread < 50% median\n"; }
}

function storageBenchmarkRunDeviceTests(string $jsonLog, string $runTs, string $label, string $runId, int $ddSizeBytes, int $devRuntime): void
{
    echo "\n== Per-device read-only benchmarks ==\n";
    $base = storageBenchmarkEntryBase($runTs, $label, $runId);
    $peer = [];
    foreach (pmssStorageHealthDiskInventoryFromLsblk((string) shell_exec('lsblk -dn -o KNAME,TYPE,ROTA,MODEL,SERIAL,SIZE 2>/dev/null')) as $meta) {
        $path = $meta['path'];
        if (!storageBenchmarkDeviceIsReadableBlock($path)) { storageBenchmarkAppendJsonLine($jsonLog, storageBenchmarkDeviceEntryBuild($base, $meta, $path, 'device-preflight', ['ok' => false, 'error' => 'not a readable block device'])); printf("%s\tskipped: not a readable block device\n", $path); continue; }
        $sizeRaw = trim((string) shell_exec('blockdev --getsize64 '.escapeshellarg($path).' 2>/dev/null'));
        if ($sizeRaw === '' || !ctype_digit($sizeRaw) || (int) $sizeRaw <= 0) { storageBenchmarkAppendJsonLine($jsonLog, storageBenchmarkDeviceEntryBuild($base, $meta, $path, 'device-preflight', ['ok' => false, 'error' => 'unable to determine block device size'])); printf("%s\tskipped: unable to determine block device size\n", $path); continue; }
        $size = (int) $sizeRaw;
        $count = (int) floor($ddSizeBytes / (1024 * 1024));
        $skip = $size > ($count * 1024 * 1024 + 4 * 1024 * 1024) ? random_int(0, (int) floor(($size - $count * 1024 * 1024) / (1024 * 1024))) : 0;
        $dd = storageBenchmarkDdSeqread($path, $count, $skip);
        $entry = storageBenchmarkDeviceEntryBuild($base, $meta, $path, 'device-seqread-dd', ['params' => ['bs' => '1M', 'count' => $count, 'skip_blocks' => $skip], 'ok' => ($dd['rc'] === 0 && $dd['mbps'] !== null)]);
        if ($dd['mbps'] !== null) $entry['metrics'] = ['seqread_MBps' => $dd['mbps'], 'elapsed_s' => $dd['secs']]; else $entry['error'] = 'dd parse failed';
        storageBenchmarkAppendJsonLine($jsonLog, $entry);
        printf("%s\tdd_seqread_MB/s=%s\n", $path, $dd['mbps'] !== null ? number_format($dd['mbps'], 2) : 'n/a');
        $iop = pmssIopingAverageMs($path);
        storageBenchmarkAppendJsonLine($jsonLog, storageBenchmarkDeviceEntryBuild($base, $meta, $path, 'device-ioping', ['ok' => ($iop !== null), 'metrics' => ['ioping_avg_ms' => round($iop ?? 0, 2)]]));
        if ($iop !== null) printf("%s\tioping_avg_ms=%.2f\n", $path, $iop);
        foreach (storageBenchmarkDeviceJobs() as $job) {
            $res = fioRun($path, $size, $devRuntime, ['name' => $job['name'], 'rw' => $job['rw'], 'bs' => $job['bs'], 'iodepth' => $job['iodepth'], 'numjobs' => 1, 'direct' => 1]);
            $entry = storageBenchmarkDeviceEntryBuild($base, $meta, $path, $job['name'], ['params' => ['rw' => $job['rw'], 'bs' => $job['bs'], 'iodepth' => $job['iodepth'], 'numjobs' => 1, 'runtime' => $devRuntime], 'ok' => $res['ok']]);
            if ($res['ok']) { $entry['metrics'] = $res['result']; printf("%s\t%s\tread_MB/s=%.2f\tread_IOPS=%.1f\tread_p95_ms=%.2f\n", $path, $job['name'], $res['result']['read_bw_MBps'], $res['result']['read_iops'], $res['result']['read_p95_ms']); } else { $entry['error'] = $res['error'] ?? 'fio failed'; }
            storageBenchmarkAppendJsonLine($jsonLog, $entry);
            if ($res['ok'] && $job['name'] === 'dev-randread-4k') $peer[$path]['fio4k_mb'] = $res['result']['read_bw_MBps'];
        }
        $peer[$path]['dd_mb'] = $dd['mbps']; $peer[$path]['iop_ms'] = $iop;
    }
    storageBenchmarkPrintPeerWarnings($peer);
}
