<?php
/**
 * Library helper: resources.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/lighttpd/userFileWrite.php';
require_once __DIR__.'/resources/accumulator.php';

const PMSS_RESOURCE_LOG_TAIL_LINES_MAX = 10080;
const PMSS_RESOURCE_LOG_TAIL_TIMEOUT_SECONDS = 5;

/** @return array<string, string> */
function pmssResourceMemoryBreakdownFieldMap(string $prefix = 'memory_'): array { return ['anon' => $prefix.'anon', 'file' => $prefix.'file']; }

/** Keep resource log file lookups on validated usernames only. */
function pmssResourceUserIsValid(string $user): bool
{
    return (function_exists('pmssNormalizeUsername') ? pmssNormalizeUsername($user) : strtolower(trim($user))) === $user
        && preg_match('/^[a-z0-9-]+$/', $user) === 1
        && ($user === 'www-data'
        || !function_exists('pmssValidateUsername')
        || pmssValidateUsername($user));
}

/** Resolve a safe per-user resource log path before shelling to tail. */
function pmssResourceLogFilePath(string $resourceDir, string $user): ?string
{
    if (!pmssResourceUserIsValid($user)) {
        return null;
    }

    $path = rtrim($resourceDir, '/').'/'.$user;
    return pmssPathTargetIsSafe($path, false, true) ? $path : null;
}

/** Keep resource tail calls bounded before they reach the shell. */
function pmssResourceTailLineCount($timePeriod): int { return max(1, min((int) $timePeriod, PMSS_RESOURCE_LOG_TAIL_LINES_MAX)); }

/** Read recent resource log records through the bounded runtime command helper. */
function pmssResourceTailLogContents(string $path, int $lines): string
{
    $tail = pmssCommandPath('tail');
    if ($tail === '') return '';
    $result = pmssCommandCapture(pmssCommandArgvShellQuote([$tail, '-n', (string) $lines, $path]), PMSS_RESOURCE_LOG_TAIL_TIMEOUT_SECONDS);
    return $result['rc'] === 0 ? trim($result['stdout']) : '';
}

/** Return the shared schema used by resource rows and totals. */
function pmssResourceReportTemplate(): array
{
    $windows = array_fill_keys(['month', 'week', 'day', 'hour'], 0.0);
    return array_fill_keys(ResourceStatsAccumulator::RAW_METRICS, $windows) + ['memory' => ['current' => 0.0, 'avg_month' => 0.0], 'tasks' => ['current' => 0.0]];
}

/** Normalize scalar metric values while rejecting malformed persisted/fallback data. */
function pmssResourceMetricValueNormalize($value): ?float { return is_numeric($value) ? (float) $value : null; }

/** Return metrics shared by resource snapshot rows and fallback calculations. */
function pmssResourceSnapshotMetricKeys(): array { return array_merge(ResourceStatsAccumulator::RAW_METRICS, ResourceStatsAccumulator::AVERAGE_METRICS); }

/** Read a stored payload metric window, defaulting missing ops windows to zero. */
function pmssResourceStoredPayloadWindowValue(array $data, string $metric, string $window): ?float
{
    $value = $data[$metric]['raw'][$window] ?? (substr($metric, -4) === '_ops' ? 0.0 : null);
    return $value !== null ? pmssResourceMetricValueNormalize($value) : null;
}

/** Read all metrics for a stored payload window. */
function pmssResourceStoredPayloadWindowMetrics(array $data, string $window): ?array
{
    $metrics = [];
    foreach (pmssResourceSnapshotMetricKeys() as $key) {
        if (($metrics[$key] = pmssResourceStoredPayloadWindowValue($data, $key, $window)) === null) return null;
    }
    return $metrics;
}

/** Normalize persisted resource stats into the row shape used by reports. */
function pmssResourceStoredPayloadReportRow(array $data): ?array
{
    $row = pmssResourceReportTemplate();
    foreach (ResourceStatsAccumulator::RAW_METRICS as $metric) {
        foreach (array_keys($row[$metric]) as $label) {
            if (($row[$metric][$label] = pmssResourceStoredPayloadWindowValue($data, $metric, $label)) === null) return null;
        }
    }

    $row['memory'] = ['current' => (float) ($data['memory']['current'] ?? 0.0), 'avg_month' => (float) ($data['memory']['raw']['month'] ?? 0.0)];
    $row['tasks'] = ['current' => (float) ($data['tasks']['current'] ?? 0.0)];
    return $row;
}

/** Build the persisted per-user resource payload from accumulator results. */
function pmssResourceStoredPayloadFromResults(array $results): array
{
    $data = ['daily' => $results['daily']];
    foreach ($results['raw'] + ['memory' => $results['memory'], 'tasks' => $results['tasks']] as $metric => $values) {
        $data[$metric] = ['raw' => $values];
    }
    $data['memory']['current'] = $results['current_memory'];
    foreach (pmssResourceMemoryBreakdownFieldMap('current_memory_') as $field => $resultKey) {
        if (isset($results[$resultKey]) && is_numeric($results[$resultKey])) $data['memory'][$field] = (float) $results[$resultKey];
    }
    $data['tasks']['current'] = $results['current_tasks'];
    return $data;
}

/** Extract one accumulator window into the resource-snapshot metric shape. */
function pmssResourceResultsWindowMetrics(array $results, string $window): ?array
{
    $metrics = [];
    foreach (pmssResourceSnapshotMetricKeys() as $metric) {
        $source = in_array($metric, ResourceStatsAccumulator::AVERAGE_METRICS, true) ? ($results[$metric] ?? null) : ($results['raw'][$metric] ?? null);
        if (!is_array($source) || !array_key_exists($window, $source) || ($metrics[$metric] = pmssResourceMetricValueNormalize($source[$window])) === null) return null;
    }
    return $metrics;
}

/** Compose the stable resource cron text-record payload after the timestamp. */
function pmssResourceLogLineParts(array $delta, array $state): array
{
    $parts = [];
    foreach (['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu_nsec'] as $field) $parts[] = (string) ($delta[$field] ?? 0);
    foreach (['memory', 'tasks'] as $field) $parts[] = (string) ($state[$field] ?? 0);
    if (isset($state['memory_anon'], $state['memory_file'])) array_push($parts, (string) $state['memory_anon'], (string) $state['memory_file']);
    return $parts;
}

/** Return the payload fields accepted by each historical resource-log line shape. */
function pmssResourceLogPayloadFields(int $payloadCount): ?array
{
    static $layouts = [
        ['io_read', 'io_write', 'cpu', 'memory', 'tasks'],
        ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'memory', 'tasks'],
        ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'memory', 'tasks', 'memory_anon', 'memory_file'],
    ];
    return ($payloadCount < 5 || $payloadCount === 8) ? null : ($payloadCount >= 9 ? $layouts[2] : ($payloadCount >= 7 ? $layouts[1] : $layouts[0]));
}

/** Parse one resource cron text record into the accumulator sample schema. */
function pmssResourceLogLineParse($line)
{
    $tokens = preg_split('/\s+/', trim((string) $line)) ?: [];
    $fields = pmssResourceLogPayloadFields(count($tokens) - 2);
    if ($fields === null) return false;
    $timestamp = strtotime($tokens[0].' '.$tokens[1]);
    if ($timestamp === false) return false;

    $parsed = ['timestamp' => (int) $timestamp] + array_fill_keys(['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'memory', 'tasks'], 0.0);
    foreach ($fields as $offset => $field) {
        $value = $tokens[$offset + 2] ?? '';
        if (!ctype_digit($value)) return false;
        $parsed[$field] = (float) $value;
    }
    return $parsed;
}

/**
 * Read and persist per-user resource statistics for PMSS hosts.
 */
class resourceStatistics
{
    /** @var string */
    private $resourceDir;

    public function __construct(array $paths = [])
    {
        $this->resourceDir = pmssDirPathResolve($paths['resource_dir'] ?? null, 'PMSS_RESOURCE_DIR', '/var/log/pmss/resources');
    }

    /**
     * Fetch resource log lines for a user from the PMSS log directory.
     *
     * @param string $user Username to load resource log entries for.
     * @param int $timePeriod Number of log lines to read from tail.
     * @return string Raw log text, possibly empty when no entries exist.
     */
    public function getData($user, $timePeriod = PMSS_RESOURCE_LOG_TAIL_LINES_MAX)
    {
        $path = pmssResourceLogFilePath($this->resourceDir, (string) $user);
        if ($path === null) {
            return '';
        }

        return pmssResourceTailLogContents($path, pmssResourceTailLineCount($timePeriod));
    }

    /**
     * Accumulate parsed resource log lines across the requested windows.
     *
     * @param string $dataLines Newline-delimited resource log payload.
     * @param array<string, int> $compareTimes Window thresholds keyed by label.
     * @param callable|null $parseErrorLogger Optional parse-failure callback.
     * @return array<string, mixed>|null
     */
    public function collectWindowResultsFromData(string $dataLines, array $compareTimes, ?callable $parseErrorLogger = null): ?array
    {
        if ($compareTimes === []) return null;
        $resourceData = pmssNonEmptyStrings(explode("\n", trim($dataLines)));
        if (count($resourceData) < 2) return null;
        $threshold = (int) min($compareTimes);
        $accumulator = new ResourceStatsAccumulator($compareTimes);
        foreach ($resourceData as $line) {
            $parsed = $this->parseLine($line);
            if ($parsed === false) {
                $parseErrorLogger !== null && $parseErrorLogger($line);
                continue;
            }
            if ($parsed['timestamp'] < $threshold) {
                continue;
            }
            $accumulator->addSample($parsed);
        }

        return $accumulator->hasSamples() ? $accumulator->results() : null;
    }

    /**
     * Parse a single resource log line into structured numeric fields.
     *
     * @param string $thisLine Raw log line to parse.
     * @return array|false
     */
    public function parseLine($thisLine)
    {
        return pmssResourceLogLineParse($thisLine);
    }
}
