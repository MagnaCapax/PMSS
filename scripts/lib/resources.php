<?php
/**
 * Library helper: resources.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/resources/storage.php';

/**
 * Read and persist per-user resource statistics for PMSS hosts.
 */
class resourceStatistics
{
    /** @var string */
    private $resourceDir;
    /** @var string */
    private $homeDir;
    /** @var string */
    private $runtimeDir;

    public function __construct(array $paths = [])
    {
        $this->resourceDir = rtrim($paths['resource_dir'] ?? getenv('PMSS_RESOURCE_DIR') ?: '/var/log/pmss/resources', '/');
        $this->homeDir = rtrim($paths['home_dir'] ?? getenv('PMSS_HOME_DIR') ?: '/home', '/');
        $this->runtimeDir = rtrim($paths['runtime_dir'] ?? getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/');
    }

    /**
     * Fetch raw resource log lines for a user from the PMSS log directory.
     */
    public function getData($user, $timePeriod = 10080)
    {
        $lines = (int) $timePeriod;
        if ($lines < 1) {
            $lines = 1;
        }
        $path = escapeshellarg($this->resourceDir.'/'.$user);
        return trim(`tail -n{$lines} {$path} 2>/dev/null`);
    }

    /**
     * Parse a single resource log line into structured data.
     *
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

        $values = array_slice($tokens, 2);
        if (count($values) < 5) {
            return false;
        }

        $parsed = [];
        foreach (array_slice($values, 0, 5) as $value) {
            if ($value === '' || !ctype_digit($value)) {
                return false;
            }
            $parsed[] = (float) $value;
        }

        return [
            'timestamp' => (int) $timestamp,
            'io_read'   => $parsed[0],
            'io_write'  => $parsed[1],
            'cpu'       => $parsed[2],
            'memory'    => $parsed[3],
            'tasks'     => $parsed[4],
        ];
    }

    /**
     * Persist the computed resource statistics for a user.
     */
    public function saveUserResources($user, $data): void
    {
        $storage = new \ResourceStorage([
            'home_dir'    => $this->homeDir,
            'runtime_dir' => $this->runtimeDir,
        ]);
        $storage->ensureRuntime();
        $storage->save($user, $data);
    }
}
