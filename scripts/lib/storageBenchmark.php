<?php
/**
 * Shared execution helpers for the non-destructive storage benchmark CLI.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';
pmssRequireRelativeFiles(__DIR__, ['cli/optionParser.php', 'storageBenchmark/report.php', 'storageBenchmark/jobs.php', 'storageBenchmark/homeDeviceInspection.php', 'storageHealth/common.php']);
pmssRequireRelativeFiles(__DIR__.'/storageBenchmark', ['cli.php', 'file.php', 'device.php', 'fio.php']);
require_once __DIR__.'/storageBenchmarkHomeDevice.php';

function storageBenchmarkAppendJsonLine(string $jsonLog, array $entry): void { if (!pmssJsonLineAppend($jsonLog, $entry)) storageBenchmarkFail("Error: failed to append JSON log entry: {$jsonLog}\n"); }
function storageBenchmarkEntryBase(string $runTs, string $label, string $runId): array { return ['timestamp' => $runTs, 'label' => $label ?: null, 'run_id' => $runId, 'run_ts' => $runTs]; }
function storageBenchmarkApplyRunResult(array $entry, array $res, string $fallbackError = 'unknown'): array { if ($res['ok']) $entry['metrics'] = $res['result']; else $entry['error'] = $res['error'] ?? $fallbackError; return $entry; }
/** Keep non-finite cached samples out of idle decisions and JSONL evidence. */
function storageBenchmarkIostatUtilPctRead(string $path): ?float
{
    $payload = pmssReadSerializedArrayFile($path);
    $util = $payload['diskUtil'] ?? null;
    if (!is_int($util) && !is_float($util) && !is_string($util)) return null;
    // Retain legacy scalar normalization, then check numeric-string overflow too.
    return pmssFiniteFloat(trim((string) $util));
}
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
