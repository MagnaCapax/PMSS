<?php
/**
 * Orchestrates per-user resource statistics calculations.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../resources.php';
require_once __DIR__.'/accumulator.php';

class ResourceStatsProcessor
{
    /** @var resourceStatistics */
    private $stats;
    /** @var string */
    private $resourceDir;
    /** @var string */
    private $homeDir;
    /** @var string */
    private $runtimeDir;
    /** @var string */
    private $statsDir;
    /** @var string */
    private $passwdFile;

    public function __construct(resourceStatistics $stats, array $paths = [])
    {
        $this->resourceDir = pmssDirPathResolve($paths['resource_dir'] ?? null, 'PMSS_RESOURCE_DIR', '/var/log/pmss/resources');
        $this->homeDir = pmssDirPathResolve($paths['home_dir'] ?? null, 'PMSS_HOME_DIR', '/home');
        $this->runtimeDir = pmssDirPathResolve($paths['runtime_dir'] ?? null, 'PMSS_RUNTIME_DIR', '/var/run/pmss');
        $this->statsDir = pmssDirPathNormalize((string) ($paths['stats_dir'] ?? ($this->runtimeDir.'/resourceStats')));
        $this->passwdFile = $paths['passwd_file'] ?? getenv('PMSS_PASSWD_FILE') ?: '/etc/passwd';

        $this->stats = $stats;
    }

    /** Ensure runtime directories exist before writing. */
    public function ensureRuntime(): void
    {
        foreach ([$this->runtimeDir => 0755, $this->statsDir => 0600] as $dir => $mode) {
            pmssEnsureSafeDir($dir, $mode);
        }
    }

    public function discoverUsers(): array
    {
        return pmssFileBasenamesDiscover($this->resourceDir.'/*');
    }

    public function spawnWorkers(string $scriptPath, array $users): void
    {
        pmssUserWorkersSpawnDetached($scriptPath, $users, '/var/log/pmss/resourceStats.log');
    }

    /** Handle the CLI worker-or-spawn flow used by the resource stats cron job. */
    public function runCli(array $argv, string $scriptPath): int
    {
        $this->ensureRuntime();
        if (isset($argv[1])) {
            $user = pmssCliUserArgSanitize($argv[1]);
            if (!$this->validateUser($user)) { echo "Invalid user specified: {$user}\n"; return 0; }
            $this->processUser($user, pmssStatsCompareTimesBuild());
            return 0;
        }
        $lockFile = $this->runtimeDir.'/resourceStats.lock';
        $lockBusy = false;
        $lockHandle = pmssLockFileAcquire($lockFile, true, 'c+', false, true, $lockBusy);
        if ($lockHandle !== false) {
            if ($lockBusy) return 0;
            pmssLockHandleWritePid($lockHandle);
        } else logMessage(date('c').": Unable to open lock file {$lockFile} for resourceStats");
        if (empty($users = $this->discoverUsers())) { echo "No users in this system!\n"; return 0; }
        $this->spawnWorkers($scriptPath, $users);
        return 0;
    }

    public function validateUser(string $user): bool
    {
        return is_readable($this->resourceDir.'/'.$user)
            && ($passwd = @file_get_contents($this->passwdFile)) !== false
            && preg_match('/^'.preg_quote($user, '/').':/m', $passwd) === 1
            && is_dir($this->homeDir.'/'.$user);
    }

    /** Process and persist resource statistics for a single user. */
    public function processUser(string $user, array $compareTimes): void
    {
        $logPrefix = date('c').': ';
        if (!$this->validateUser($user)) {
            logMessage($logPrefix."Invalid user {$user}");
            return;
        }

        if (($dataLines = $this->stats->getData($user, (int) ((35 * 24 * 60) / 5))) === '') {
            logMessage($logPrefix."No data for user {$user}");
            return;
        }

        if (count($resourceData = array_filter(explode("\n", $dataLines))) < 2) {
            logMessage($logPrefix."Too little data for {$user}");
            return;
        }

        $results = $this->stats->collectWindowResultsFromData(
            $dataLines,
            $compareTimes,
            static function (string $line) use ($logPrefix, $user): void {
                logMessage($logPrefix."Parsing line failed for {$user}, line: {$line}");
            }
        );
        if ($results === null) {
            logMessage($logPrefix."No valid samples for {$user}");
            return;
        }

        $metricData = $results['raw'] + [
            'memory' => $results['memory'],
            'tasks' => $results['tasks'],
        ];
        foreach (array_merge(ResourceStatsAccumulator::RAW_METRICS, ['memory', 'tasks']) as $metric) {
            $data[$metric] = ['raw' => $metricData[$metric]];
        }
        $data['memory']['current'] = $results['current_memory'];
        foreach (['anon' => 'current_memory_anon', 'file' => 'current_memory_file'] as $field => $resultKey) {
            if (isset($results[$resultKey]) && is_numeric($results[$resultKey])) {
                $data['memory'][$field] = (float) $results[$resultKey];
            }
        }
        $data['tasks']['current'] = $results['current_tasks'];
        $data['daily'] = $results['daily'];

        $this->ensureRuntime();
        $this->save($user, $data);
        logMessage($logPrefix."Resource stats for {$user} saved, month read bytes: {$results['raw']['io_read']['month']}");
    }

    /** Persist user resource data to home directory and runtime cache. */
    private function save(string $user, array $data): void
    {
        $serialized = serialize($data);
        $homePath = $this->homeDir.'/'.$user;

        $targets = [[$this->statsDir.'/'.$user, 'root', 0600, false]];
        is_dir($homePath) && array_unshift($targets, [$homePath.'/.resourceData', $user, 0640, true]);

        foreach ($targets as [$path, $group, $mode, $immutable]) {
            pmssTrafficWriteFile($path, $serialized, $group, $mode, $immutable);
        }
    }
}
