<?php
/**
 * Library helper: resources.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/resources/accumulator.php';
require_once __DIR__.'/traffic/storage.php';

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

/** Return the shared schema used by resource rows and totals. */
function pmssResourceReportTemplate(): array
{
    $windows = array_fill_keys(['month', 'week', 'day', 'hour'], 0.0);
    return array_fill_keys(ResourceStatsAccumulator::RAW_METRICS, $windows) + ['memory' => ['current' => 0.0, 'avg_month' => 0.0], 'tasks' => ['current' => 0.0]];
}

/** Read a stored payload metric window, defaulting missing ops windows to zero. */
function pmssResourceStoredPayloadWindowValue(array $data, string $metric, string $window): ?float
{
    $value = $data[$metric]['raw'][$window] ?? (substr($metric, -4) === '_ops' ? 0.0 : null);
    return ($value !== null && is_numeric($value)) ? (float) $value : null;
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
    public function getData($user, $timePeriod = 10080)
    {
        if (!pmssResourceUserIsValid((string) $user)) {
            return '';
        }

        $lines = max(1, (int) $timePeriod);
        $path = escapeshellarg($this->resourceDir.'/'.$user);
        return trim(`tail -n{$lines} {$path} 2>/dev/null`);
    }

    /**
     * Read the persisted day window payload used by resource snapshots.
     *
     * @param string $path Serialized resource payload path.
     * @return array<string, float>|null
     */
    public function readSnapshotMetricsFromPath(string $path): ?array
    {
        $data = pmssReadSerializedArrayFile($path);
        if ($data === null) return null;
        $metrics = [];
        foreach (array_merge(ResourceStatsAccumulator::RAW_METRICS, ['memory', 'tasks']) as $key) {
            if (($metrics[$key] = pmssResourceStoredPayloadWindowValue($data, $key, 'day')) === null) return null;
        }

        return $metrics;
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
        $resourceData = array_values(array_filter(explode("\n", trim($dataLines)), 'strlen'));
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
        if (!is_array($tokens = preg_split('/\s+/', trim((string) $thisLine))) || ($tokenCount = count($tokens)) < 7) {
            return false;
        }

        $timestamp = strtotime($tokens[0].' '.$tokens[1]);
        if ($timestamp === false) return false;
        $parsed = ['timestamp' => (int) $timestamp] + array_fill_keys(
            ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'memory', 'tasks'],
            0.0
        );
        foreach (($tokenCount >= 9
            ? [2 => 'io_read', 3 => 'io_write', 4 => 'io_read_ops', 5 => 'io_write_ops', 6 => 'cpu', 7 => 'memory', 8 => 'tasks']
            : [2 => 'io_read', 3 => 'io_write', 4 => 'cpu', 5 => 'memory', 6 => 'tasks']) as $index => $field) {
            if (!ctype_digit($tokens[$index] ?? '')) {
                return false;
            }
            $parsed[$field] = (float) $tokens[$index];
        }

        if ($tokenCount > 10) {
            foreach (array_values(pmssResourceMemoryBreakdownFieldMap()) as $offset => $field) {
                if (!ctype_digit($tokens[$offset + 9] ?? '')) {
                    return false;
                }
                $parsed[$field] = (float) $tokens[$offset + 9];
            }
        }

        return $tokenCount !== 10 ? $parsed : false;
    }
}
