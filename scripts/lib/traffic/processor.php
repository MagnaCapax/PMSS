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

    /** Build comparison timestamps for each window. */
    public function buildCompareTimes(): array
    {
        return pmssStatsCompareTimesBuild();
    }

    /** Detect whether we are running in worker mode for a specific user. */
    public function detectWorkerUser(array $argv): ?string
    {
        return isset($argv[1]) ? pmssCliUserArgSanitize($argv[1]) : null;
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
        if (($user = $this->detectWorkerUser($argv)) !== null) {
            if (!$this->validateUser($user)) {
                echo "Invalid user specified: {$user}\n";
                return 0;
            }

            $this->processUser($user, $this->buildCompareTimes());
            return 0;
        }

        $users = $this->discoverUsers();
        if (empty($users)) {
            echo "No users in this system!\n";
            return 0;
        }

        $this->spawnWorkers($scriptPath, $users);
        return 0;
    }

    /** Sanitize user input by stripping unexpected characters. */
    public function sanitizeUser(string $input): string
    {
        return pmssCliUserArgSanitize($input);
    }

    /** Validate that a user has traffic data and a home directory. */
    public function validateUser(string $username): bool
    {
        $baseUser = pmssTrafficUserKeyBaseUser($username);
        return is_readable($this->trafficDir.'/'.$username)
            && is_dir($this->homeDir.'/'.$baseUser)
            && is_string($passwd = @file_get_contents($this->passwdFile))
            && preg_match('/^'.preg_quote($baseUser, '/').':/m', $passwd) === 1;
    }

    /** Process and persist traffic statistics for a single user. */
    public function processUser(string $user, array $compareTimes): void
    {
        if (!$this->validateUser($user)) {
            logMessage(date('c').": Invalid user {$user}");
            return;
        }

        if (($dataLines = trim($this->stats->getData($user, (int) ((35 * 24 * 60) / 5)))) === '') {
            logMessage(date('c').": No data for user {$user}");
            return;
        }

        if (count($trafficData = array_filter(explode("\n", $dataLines))) < 2) {
            logMessage(date('c').": Too little data for {$user}");
            return;
        }

        $rawTotals = array_fill_keys(array_keys($compareTimes), 0.0);
        $dailyTotals = [];
        $firstDay = '';

        foreach ($trafficData as $line) {
            $parsed = $this->stats->parseLine($line);
            if ($parsed === false) {
                logMessage(date('c').": Parsing line failed for {$user}, line: {$line}");
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
            'display' => array_map('pmssTrafficFormatAmount', $rawTotals),
            'daily'   => $dailyTotals,
        ]);
        logMessage(date('c').": Traffic stats for {$user} saved, month data consumption: {$rawTotals['month']}");
    }
}
