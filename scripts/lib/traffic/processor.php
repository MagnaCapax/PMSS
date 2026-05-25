<?php
/**
 * Orchestrates per-user traffic statistics calculations.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../traffic.php';
require_once __DIR__.'/../stats/userStatsProcessor.php';

class TrafficStatsProcessor extends PmssUserStatsProcessor
{
    /** @var trafficStatistics */
    private $stats;

    public function __construct(trafficStatistics $stats, array $paths = [])
    {
        parent::__construct($paths, 'traffic_dir', 'PMSS_TRAFFIC_DIR', '/var/log/pmss/traffic', '/var/log/pmss/trafficStats.log');
        $this->stats = $stats;
    }

    /** Validate that a user has traffic data and a home directory. */
    public function validateUser(string $username): bool
    {
        return $this->statsUserHasDataHomeAndPasswd($username, pmssTrafficUserKeyBaseUser($username));
    }

    /** Process and persist traffic statistics for a single user. */
    public function processUser(string $user, array $compareTimes): void
    {
        $logPrefix = date('c').': ';
        $loadedData = $this->loadStatsDataLines($user, [$this->stats, 'getData'], $logPrefix, true);
        if ($loadedData === null) {
            return;
        }
        $trafficData = $loadedData['records'];

        $rawTotals = array_fill_keys(array_keys($compareTimes), 0.0);
        $dailyTotals = [];
        $firstDay = '';

        foreach ($trafficData as $line) {
            $parsed = $this->stats->parseLine($line);
            if ($parsed === false) {
                logMessage($logPrefix."Parsing line failed for {$user}, line: {$line}");
                continue;
            }

            foreach ($compareTimes as $label => $threshold) {
                if ($parsed['timestamp'] >= $threshold) {
                    $rawTotals[$label] += $parsed['data'];
                }
            }

            $currentDay = date('Y/m/d', $parsed['timestamp']);
            $firstDay = ($firstDay === '') ? $currentDay : $firstDay;
            if ($currentDay !== $firstDay) {
                $dailyTotals[$currentDay] = ($dailyTotals[$currentDay] ?? 0) + $parsed['data'];
            }
        }

        $this->stats->saveUserTraffic($user, [
            'raw'     => $rawTotals,
            'daily'   => $dailyTotals,
        ]);
        logMessage($logPrefix."Traffic stats for {$user} saved, month data consumption: {$rawTotals['month']}");
    }
}
