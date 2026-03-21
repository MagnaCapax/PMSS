<?php
/**
 * Library helper: resources.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Read and persist per-user resource statistics for PMSS hosts.
 */
class resourceStatistics
{
    /** @var string */
    private $resourceDir;

    public function __construct(array $paths = [])
    {
        $this->resourceDir = rtrim($paths['resource_dir'] ?? getenv('PMSS_RESOURCE_DIR') ?: '/var/log/pmss/resources', '/');
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
        if ($timestamp === false) {
            return false;
        }

        $parsed = ['timestamp' => (int) $timestamp] + array_fill_keys(
            ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'memory', 'tasks'],
            0.0
        );
        $fields = $tokenCount >= 9 ? ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'memory', 'tasks'] : ['io_read', 'io_write', 'cpu', 'memory', 'tasks'];
        foreach ($fields as $index => $field) {
            $value = $tokens[$index + 2] ?? '';
            if ($value === '' || !ctype_digit($value)) {
                return false;
            }
            $parsed[$field] = (float) $value;
        }

        if ($tokenCount === 10) {
            return false;
        }

        if ($tokenCount > 10) {
            foreach ([9 => 'memory_anon', 10 => 'memory_file'] as $index => $field) {
                $value = $tokens[$index] ?? '';
                if ($value === '' || !ctype_digit($value)) {
                    return false;
                }
                $parsed[$field] = (float) $value;
            }
        }

        return $parsed;
    }
}
