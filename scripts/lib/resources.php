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
        $tokens = preg_split('/\s+/', trim((string) $thisLine));
        if (!is_array($tokens) || count($tokens) < 7) {
            return false;
        }

        $timestamp = strtotime($tokens[0].' '.$tokens[1]);
        if ($timestamp === false) {
            return false;
        }

        $usesOpsFields = count($tokens) >= 9;
        $valueCount = $usesOpsFields ? 7 : 5;
        $values = array_slice($tokens, 2, $valueCount);
        $parsed = [];
        foreach ($values as $value) {
            if ($value === '' || !ctype_digit($value)) {
                return false;
            }
            $parsed[] = (float) $value;
        }

        $ioReadOps = 0.0;
        $ioWriteOps = 0.0;
        if ($usesOpsFields) {
            $ioReadOps = $parsed[2];
            $ioWriteOps = $parsed[3];
        }

        return [
            'timestamp' => (int) $timestamp,
            'io_read'   => $parsed[0],
            'io_write'  => $parsed[1],
            'io_read_ops' => $ioReadOps,
            'io_write_ops' => $ioWriteOps,
            'cpu'       => $usesOpsFields ? $parsed[4] : $parsed[2],
            'memory'    => $usesOpsFields ? $parsed[5] : $parsed[3],
            'tasks'     => $usesOpsFields ? $parsed[6] : $parsed[4],
        ];
    }
}
