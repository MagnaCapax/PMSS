<?php
/**
 * Library helper: traffic.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/traffic/storage.php';

/** Format a traffic amount stored in MiB using PMSS CLI conventions. */
function pmssTrafficFormatAmount(float $valueMiB): string
{
    if ($valueMiB > (1024 * 1024)) return round(($valueMiB / 1024 / 1024), 2).'TiB';
    if ($valueMiB > 1024) return round(($valueMiB / 1024), 2).'GiB';
    return round($valueMiB, 2).'MiB';
}

/** Parse one iptables OUTPUT rule line into bytes, destination, and UID. */
function pmssTrafficParseOutputRule(string $line): ?array
{
    $columns = preg_split('/\s+/', trim($line));
    $ownerIndex = is_array($columns) ? array_search('owner', $columns, true) : false;
    if (!is_array($columns) || count($columns) < 11 || !ctype_digit($columns[1]) || $columns[2] !== 'ACCEPT' || $ownerIndex === false || $ownerIndex < 1 || !isset($columns[$ownerIndex + 3]) || $columns[$ownerIndex + 1] !== 'UID' || $columns[$ownerIndex + 2] !== 'match' || !ctype_digit($columns[$ownerIndex + 3])) return null;
    return ['bytes' => (int) $columns[1], 'destination' => $columns[$ownerIndex - 1], 'uid' => (int) $columns[$ownerIndex + 3]];
}
/** Parse OUTPUT chain accounting into external/local per-UID byte totals. */
function pmssTrafficParseOutputUsage(string $usage, array $localnets): array
{
    $traffic = $local = [];
    $unmatched = 0;
    $localnetLookup = array_fill_keys(array_values(array_filter($localnets, 'is_string')), true);
    foreach (preg_split('/\r?\n/', trim($usage)) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;
        if (strpos($trimmed, 'Chain OUTPUT (') === 0) {
            preg_match('/,\s*([0-9]+)\s+bytes\)/', $trimmed, $matches) === 1 && $unmatched = (int) $matches[1];
            continue;
        }
        $rule = pmssTrafficParseOutputRule($trimmed);
        if ($rule === null) continue;
        $bucket = $rule['destination'] === '0.0.0.0/0' ? 'traffic' : (isset($localnetLookup[$rule['destination']]) ? 'local' : '');
        $bucket !== '' && ${$bucket}[$rule['uid']] = (${$bucket}[$rule['uid']] ?? 0) + $rule['bytes'];
    }
    return ['traffic' => $traffic, 'local' => $local, 'unmatched' => $unmatched];
}
/** Log and notify when a traffic sample exceeds the five-minute link budget. */
function pmssTrafficBudgetExceeded(string $path, string $user, string $label, int $bytes, ?float $linkSpeed, string $debugBody, string $userMessage): bool
{
    if (!pmssResourceLogExceedsFiveMinuteLinkBudget($bytes, $linkSpeed)) return false;
    pmssAppendRootTimestampedLogEntry($path, ": User {$user} {$label} exceeds 90% link max: {$bytes}\n{$debugBody}\n");
    function_exists('pmssUserLog') && pmssUserLog($user, sprintf($userMessage, $bytes));
    return true;
}

/**
 * Read and persist per-user traffic statistics for PMSS hosts.
 *
 * This helper offers a thin wrapper around the on-disk traffic logs and the
 * `TrafficStorage` implementation so callers can fetch recent utilisation and
 * append new samples without knowing the underlying file layout.
 */
class trafficStatistics {
    private $trafficDir;
    private $storage;

    /** Configure traffic storage paths and mode. */
    public function __construct(array $paths = [])
    {
        $this->trafficDir = pmssDirPathResolve($paths['traffic_dir'] ?? null, 'PMSS_TRAFFIC_DIR', '/var/log/pmss/traffic');
        $homeDir = pmssDirPathResolve($paths['home_dir'] ?? null, 'PMSS_HOME_DIR', '/home');
        $runtimeDir = pmssDirPathResolve($paths['runtime_dir'] ?? null, 'PMSS_RUNTIME_DIR', '/var/run/pmss');
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
        return trim(`tail -n{$lines} {$path} 2>/dev/null`);
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
        if ($data > 150000) return false;    // Pruning erroneous data, 7500Mb in max 6 minutes or so? Yeap.
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
     * @return void
     */
    public function saveUserTraffic($user, $data) {
        $this->storage->ensureRuntime(); $this->storage->save($user, $data);
    }
}
