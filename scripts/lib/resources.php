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
        $data = pmssTrafficReadSerializedArrayFile($path);
        if ($data === null) return null;
        $metrics = [];
        foreach (['io_read', 'io_write', 'cpu', 'memory', 'ram_hours', 'tasks'] as $key) {
            $value = $data[$key]['raw']['day'] ?? null;
            if ($value === null || !is_numeric($value)) {
                return null;
            }
            $metrics[$key] = (float) $value;
        }
        $metrics['io_read_ops'] = (float) ($data['io_read_ops']['raw']['day'] ?? 0.0);
        $metrics['io_write_ops'] = (float) ($data['io_write_ops']['raw']['day'] ?? 0.0);

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
