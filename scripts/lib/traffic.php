<?php
/**
 * Library helper: traffic.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/traffic/storage.php';

/**
 * Read and persist per-user traffic statistics for PMSS hosts.
 *
 * This helper offers a thin wrapper around the on-disk traffic logs and the
 * `TrafficStorage` implementation so callers can fetch recent utilisation and
 * append new samples without knowing the underlying file layout.
 */
class trafficStatistics {
    /** @var string */
    private $trafficDir;
    /** @var TrafficStorage */
    private $storage;

    public function __construct(array $paths = [])
    {
        $this->trafficDir = rtrim($paths['traffic_dir'] ?? getenv('PMSS_TRAFFIC_DIR') ?: '/var/log/pmss/traffic', '/');
        $homeDir = rtrim($paths['home_dir'] ?? getenv('PMSS_HOME_DIR') ?: '/home', '/');
        $runtimeDir = rtrim($paths['runtime_dir'] ?? getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/');
        $this->storage = new \TrafficStorage([
            'home_dir' => $homeDir,
            'runtime_dir' => $runtimeDir,
            'traffic_mode' => $paths['traffic_mode'] ?? 'egress',
        ]);
    }

	/**
	 * Fetch raw traffic log lines for a user from the PMSS log directory.
	 *
	 * Reads at most the requested number of lines from
	 * `/var/log/pmss/traffic/<user>` and trims trailing whitespace. Missing
	 * or unreadable files yield an empty string.
	 *
	 * @param string $user       Username whose traffic log should be tailed.
	 * @param int    $timePeriod Number of lines to read from the log.
	 *
	 * @return string Raw log slice, possibly empty when no data is available.
	 */
	public function getData($user, $timePeriod = 5050) {
        $lines = max(1, (int) $timePeriod);
        $path = escapeshellarg($this->trafficDir.'/'.$user);
		return trim( `tail -n{$lines} {$path} 2>/dev/null` );
	}
	    
    /**
     * Parse a single traffic log line into structured data.
     *
     * Validates the format, converts the timestamp to a UNIX epoch and the
     * recorded byte count into megabytes, and discards clearly unreasonable
     * samples that would skew reporting or suggest corrupt input.
     *
     * @param string $thisLine Raw line from the traffic log.
     *
     * @return array|false Normalised sample with `data` and `timestamp` keys
     *                     or false when the line cannot be parsed safely.
     */
    public function parseLine($thisLine) {
        $parts = explode(': ', $thisLine);

	        if (count($parts) !== 2) return false;    // Erroneous data, too many parts :
	        $data = (float) trim($parts[1]) / 1024 / 1024;   // Transform from bytes to megabytes

	        if ($data > 150000) { return false; }    // Pruning erroneous data, 7500Mb in max 6 minutes or so? Yeap.

        return [
            'data' => $data,
            'timestamp' => strtotime(trim($parts[0])),
        ];
    }
    
    /**
     * Persist a newly observed traffic sample for the given user.
     *
     * Delegates to the `TrafficStorage` helper, which is responsible for
     * ensuring on-disk structures exist and writing the data safely.
     *
     * @param string $user Username whose traffic data should be recorded.
     * @param array  $data Structured traffic statistics payload.
     *
     * @return void
     */
    public function saveUserTraffic( $user, $data ) {
        $this->storage->ensureRuntime();
        $this->storage->save($user, $data);
    }

}
