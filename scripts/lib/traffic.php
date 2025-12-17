<?php

require_once __DIR__.'/traffic/storage.php';

/**
 * Read and persist per-user traffic statistics for PMSS hosts.
 *
 * This helper offers a thin wrapper around the on-disk traffic logs and the
 * `TrafficStorage` implementation so callers can fetch recent utilisation and
 * append new samples without knowing the underlying file layout.
 */
class trafficStatistics {

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
		return trim( `tail -n{$timePeriod} /var/log/pmss/traffic/{$user} 2>/dev/null` );
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
        $thisLine = trim( $thisLine );        
        if (empty($thisLine)) return false;
        if (strpos($thisLine, ': ') === false) return false;
        $thisLine = explode(': ', $thisLine);
        
	        if (count($thisLine) != 2) return false;    // Erroneous data, too many parts :
	        $thisTime = strtotime( trim($thisLine[0]) );
	        $thisData = (float) trim($thisLine[1]) / 1024 / 1024;   // Transform from bytes to megabytes
	        
	        if ($thisData > 150000 ) { return false; }    // Pruning erroneous data, 7500Mb in max 6 minutes or so? Yeap.
        
        return array(
            'data' => $thisData,
            'timestamp' => $thisTime
        );
    }
    
    /**
     * Persist a newly observed traffic sample for the given user.
     *
     * Delegates to the `TrafficStorage` helper, which is responsible for
     * ensuring on-disk structures exist and appending the data safely.
     *
     * @param string $user Username whose traffic data should be recorded.
     * @param float  $data Amount of traffic in megabytes for the sample.
     *
     * @return void
     */
    public function saveUserTraffic( $user, $data ) {
        $storage = new \TrafficStorage();
        $storage->ensureRuntime();
        $storage->save($user, $data);
    }

}
