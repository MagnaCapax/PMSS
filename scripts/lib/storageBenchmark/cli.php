<?php
/** Storage benchmark CLI policy and checked option handling. @license GPL-3.0-only */

/** Emit a legacy CLI fatal error and preserve the historical exit code. */
function storageBenchmarkFail(string $message): void { exit(pmssCliReturnWithStderr($message)); }
/** Reject corrupt raw bytes before whitespace normalization can hide them. */
function storageBenchmarkRequireSizeBytes(string $optionName, string $value, int $minimumBytes = 1, string $minimumLabel = 'positive size'): int { $bytes = !pmssFilesystemPathHasNulByte($value) && preg_match('/^([0-9]+)([KMGTP]i?B?)?$/i', trim($value)) === 1 ? pmssParseSizeToBytes($value, true, true) : null; if ($bytes === null || $bytes <= 0.0 || !is_finite($bytes) || $bytes >= (float) PHP_INT_MAX) storageBenchmarkFail("Error: {$optionName} must be a positive size (examples: 1G, 512M, 1048576).\n"); if ($bytes < $minimumBytes) storageBenchmarkFail("Error: {$optionName} must be at least {$minimumLabel}.\n"); return (int) $bytes; }
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
    $usage = "\nStorage benchmark (non-destructive)\n".pmssCliHelpUsageOptions('storageBenchmark.php [options]', [
        ['--target <dir>', 'Directory for file-backed tests (default /home).'], ['--size <bytes|MiB|GiB>', 'Target file size (default 500G, capped to 80% free).'],
        ['--runtime <seconds>', 'Per-test runtime for volume fio tests (default 60).'], ['--label <name>', 'Tag results (e.g., hostname/site/array).'],
        ['--json <path>', 'JSON Lines log (default /var/log/pmss/benchmark-storage.jsonl).'], ['--devices', 'Enable per-device tests (dd seqread + fio randread).'],
        ['--home-device', 'Read-only test of the block device under --target (default /home); skips file and per-member device tests.'],
        ['--dd-size <MiB|GiB>', 'Size for dd seqread per device (default 1G).'], ['--device-runtime <sec>', 'Per-device fio runtime (default 30).'],
        ['--require-idle', 'Abort if busy (ioping/iostat exceed thresholds).'], ['--idle-latency-ms <ms>', 'ioping avg latency threshold (default 100).'],
        ['--idle-util <percent>', 'iostat util threshold (default 85).'], ['--show-last', 'Print the last run human summary and exit.'],
    ], 28, ['Also accepts --key=value form for all value options.']);
    if (($parsed = pmssParseCliTokensOrHelp($argv, $usage, ['--target', '--size', '--json', '--label', '--dd-size', '--runtime', '--device-runtime', '--idle-latency-ms', '--idle-util'])) === null) return 0;

    $testDevices = pmssCliOptionPresent($parsed, 'devices', null, true); $homeDevice = pmssCliOptionPresent($parsed, 'home-device', null, true); $requireIdle = pmssCliOptionPresent($parsed, 'require-idle', null, true);
    $targetDir = (string) pmssCliOptionString($parsed, 'target', null, '/home', true); $fileSize = (string) pmssCliOptionString($parsed, 'size', null, '500G', true); $jsonLog = (string) pmssCliOptionString($parsed, 'json', null, '/var/log/pmss/benchmark-storage.jsonl', true);
    $label = (string) pmssCliOptionString($parsed, 'label', null, '', true); $ddSize = (string) pmssCliOptionString($parsed, 'dd-size', null, '1G', true);
    if (pmssCliOptionPresent($parsed, 'show-last', null, true)) return storageBenchmarkShowLast($jsonLog);

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

    storageBenchmarkPrintFileSummary($targetDir, $jsonLog, $label, storageBenchmarkRunFileTests($targetDir, $jsonLog, $runTs, $label, $runId, $mntDev, $fs, $requested, $runtime));
    if ($testDevices) storageBenchmarkRunDeviceTests($jsonLog, $runTs, $label, $runId, $ddSizeBytes, $devRuntime);
    return 0;
}

function storageBenchmarkRequireCommandField(string $command, string $label, bool $positiveInt = false): string
{
    $value = storageBenchmarkCommandFieldRead($command, $positiveInt);
    if ($value === null) storageBenchmarkFail("Error: failed to read {$label}.\n");
    return $value;
}
