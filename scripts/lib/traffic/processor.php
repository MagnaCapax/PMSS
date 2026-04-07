<?php
/**
 * Orchestrates per-user traffic statistics calculations.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../traffic.php';

class TrafficStatsProcessor
{
    /** @var trafficStatistics */
    private $stats;
    /** @var string */
    private $trafficDir;
    /** @var string */
    private $homeDir;
    /** @var string */
    private $passwdFile;

    public function __construct(trafficStatistics $stats, array $paths = [])
    {
        $this->stats           = $stats;
        $this->trafficDir      = pmssDirPathResolve($paths['traffic_dir'] ?? null, 'PMSS_TRAFFIC_DIR', '/var/log/pmss/traffic');
        $this->homeDir         = pmssDirPathResolve($paths['home_dir'] ?? null, 'PMSS_HOME_DIR', '/home');
        $this->passwdFile      = $paths['passwd_file'] ?? getenv('PMSS_PASSWD_FILE') ?: '/etc/passwd';
    }

    /** Discover users by scanning the traffic log directory. */
    public function discoverUsers(): array
    {
        return pmssFileBasenamesDiscover($this->trafficDir.'/*');
    }

    /** Launch detached workers for each user to process in parallel. */
    public function spawnWorkers(string $scriptPath, array $users): void
    {
        pmssUserWorkersSpawnDetached($scriptPath, $users, '/var/log/pmss/trafficStats.log');
    }

    /** Handle the CLI worker-or-spawn flow used by traffic cron entrypoints. */
    public function runCli(array $argv, string $scriptPath): int
    {
        return pmssStatsProcessorRunCli(
            $argv,
            $scriptPath,
            [$this, 'validateUser'],
            [$this, 'processUser'],
            [$this, 'discoverUsers'],
            [$this, 'spawnWorkers']
        );
    }

    /** Validate that a user has traffic data and a home directory. */
    public function validateUser(string $username): bool
    {
        $baseUser = pmssTrafficUserKeyBaseUser($username);
        return is_readable($this->trafficDir.'/'.$username)
            && is_dir($this->homeDir.'/'.$baseUser)
            && pmssPasswdFileHasUser($this->passwdFile, $baseUser);
    }

    /** Process and persist traffic statistics for a single user. */
    public function processUser(string $user, array $compareTimes): void
    {
        $logPrefix = date('c').': ';
        $loadedData = pmssStatsProcessorDataLinesLoad(
            $user,
            [$this, 'validateUser'],
            [$this->stats, 'getData'],
            $logPrefix,
            true
        );
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
