#!/usr/bin/env php
<?php
/** Non-destructive storage benchmark (fio wrapper). */

require_once __DIR__.'/../lib/storageBenchmark.php';

$valueOptionNames = ['--target', '--size', '--json', '--label', '--dd-size', '--runtime', '--device-runtime', '--idle-latency-ms', '--idle-util'];
// Keep long flag literals inline for CLI characterization coverage: '--require-idle'.
$parsed = pmssParseCliTokens($argv, $valueOptionNames);
if (pmssCliHelpRequested($parsed)) {
    echo "\nStorage benchmark (non-destructive)\n";
    echo pmssCliHelpUsageOptions('storageBenchmark.php [options]', [
        ['--target <dir>', 'Directory for file-backed tests (default /home).'],
        ['--size <bytes|MiB|GiB>', 'Target file size (default 500G, capped to 80% free).'],
        ['--runtime <seconds>', 'Per-test runtime for volume fio tests (default 60).'],
        ['--label <name>', 'Tag results (e.g., hostname/site/array).'],
        ['--json <path>', 'JSON Lines log (default /var/log/pmss/benchmark-storage.jsonl).'],
        ['--devices', 'Enable per-device tests (dd seqread + fio randread).'],
        ['--dd-size <MiB|GiB>', 'Size for dd seqread per device (default 1G).'],
        ['--device-runtime <sec>', 'Per-device fio runtime (default 30).'],
        ['--require-idle', 'Abort if busy (ioping/iostat exceed thresholds).'],
        ['--idle-latency-ms <ms>', 'ioping avg latency threshold (default 100).'],
        ['--idle-util <percent>', 'iostat util threshold (default 85).'],
        ['--show-last', 'Print the last run human summary and exit.'],
        ['--help', 'Show this help.'],
    ], 28, ['Also accepts --key=value form for all value options.']);
    exit(0);
}

$testDevices = pmssCliOptionPresent($parsed, 'devices', null, true); $requireIdle = pmssCliOptionPresent($parsed, 'require-idle', null, true); $showLast = pmssCliOptionPresent($parsed, 'show-last', null, true);
$targetDir = (string) pmssCliOptionString($parsed, 'target', null, '/home', true); $fileSize = (string) pmssCliOptionString($parsed, 'size', null, '500G', true); $jsonLog = (string) pmssCliOptionString($parsed, 'json', null, '/var/log/pmss/benchmark-storage.jsonl', true);
$label = (string) pmssCliOptionString($parsed, 'label', null, '', true); $ddSize = (string) pmssCliOptionString($parsed, 'dd-size', null, '1G', true);
if ($showLast) exit(storageBenchmarkShowLast($jsonLog));

$runtime = storageBenchmarkRequireIntOption($parsed, 'runtime', 60, 1, 'positive'); $devRuntime = $testDevices ? storageBenchmarkRequireIntOption($parsed, 'device-runtime', 30, 1, 'positive') : 30;
$idleLatencyMs = storageBenchmarkRequireIntOption($parsed, 'idle-latency-ms', 100, 0, 'non-negative'); $idleUtilPct = storageBenchmarkRequireIntOption($parsed, 'idle-util', 85, 0, 'non-negative');
$requested = storageBenchmarkRequirePositiveSizeBytes('--size', $fileSize); $ddSizeBytes = $testDevices ? storageBenchmarkRequireMinimumSizeBytes('--dd-size', storageBenchmarkRequirePositiveSizeBytes('--dd-size', $ddSize), 1024 * 1024, '1 MiB') : 0;
storageBenchmarkRequireJsonLogPath($jsonLog);
$targetDir = storageBenchmarkRequireTargetDir($targetDir);

if (pmssCommandPath('fio') === '') { fwrite(STDERR, "Error: 'fio' not found.\n"); exit(1); }

$runId = date('YmdHis').'-'.bin2hex(random_bytes(3)); $runTs = date('c');
$fs = storageBenchmarkRequireCommandField('stat -f -c %T '.escapeshellarg($targetDir), 'filesystem type'); $mntDev = storageBenchmarkRequireCommandField('df -P '.escapeshellarg($targetDir).' | awk '.escapeshellarg('NR==2 {print $1}'), 'mount device');
$pre = storageBenchmarkEntryBase($runTs, $label, $runId) + ['target_dir' => $targetDir, 'test' => 'preflight-idle', 'ok' => true];
$pre['ioping_avg_ms'] = pmssIopingAverageMs($targetDir);
if (($pre['ioping_avg_ms'] ?? 0) > $idleLatencyMs) { $pre['ok'] = false; $pre['warn'] = 'ioping above threshold'; }
$iostatUtilPct = storageBenchmarkIostatUtilPctRead('/var/run/pmss/iostat');
if ($iostatUtilPct !== null) { $pre['iostat_util_pct'] = $iostatUtilPct; if ($pre['iostat_util_pct'] > $idleUtilPct) { $pre['ok'] = false; $pre['warn_util'] = 'iostat util high'; } }
storageBenchmarkAppendJsonLine($jsonLog, $pre);
if ($requireIdle && !$pre['ok']) { fwrite(STDERR, "Busy system (--require-idle): aborting.\n"); exit(2); }

$summary = storageBenchmarkRunFileTests($targetDir, $jsonLog, $runTs, $label, $runId, $mntDev, $fs, $requested, $runtime);
storageBenchmarkPrintFileSummary($targetDir, $jsonLog, $label, $summary);

if ($testDevices) storageBenchmarkRunDeviceTests($jsonLog, $runTs, $label, $runId, $ddSizeBytes, $devRuntime);
