<?php
/**
 * Orchestrates per-user resource statistics calculations.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../resources.php';
require_once __DIR__.'/../stats/userStatsProcessor.php';

class ResourceStatsProcessor extends PmssUserStatsProcessor
{
    /** @var resourceStatistics */
    private $stats;
    /** @var string */
    private $runtimeDir;
    /** @var string */
    private $statsDir;
    /** @var callable */
    private $logger;
    /** @var resource|false|null */
    private $lockHandle;

    public function __construct(resourceStatistics $stats, array $paths = [])
    {
        parent::__construct($paths, 'resource_dir', 'PMSS_RESOURCE_DIR', '/var/log/pmss/resources', '/var/log/pmss/resourceStats.log');
        $this->runtimeDir = pmssDirPathResolve($paths['runtime_dir'] ?? null, 'PMSS_RUNTIME_DIR', '/var/run/pmss');
        $this->statsDir = pmssDirPathNormalize((string) ($paths['stats_dir'] ?? ($this->runtimeDir.'/resourceStats')));
        $this->stats = $stats;
        $this->logger = $paths['logger'] ?? 'logMessage';
    }

    /** Emit processor diagnostics through the configured logger. */
    private function log(string $message): void
    {
        $logger = $this->logger;
        $logger($message);
    }

    /** Ensure runtime directories exist before writing. */
    public function ensureRuntime(): void
    {
        pmssManagedDirsEnsure([$this->runtimeDir => 0755, $this->statsDir => 0600], function (string $dir): void { $this->log(date('c').": Unable to prepare resource runtime directory {$dir}"); });
    }

    /** Ensure resource runtime paths are ready before any CLI mode runs. */
    protected function beforeRunCli(): void
    {
        $this->ensureRuntime();
    }

    /** Hold the cron spawn lock while detached workers start. */
    public function beforeSpawn(): bool
    {
        $lockFile = $this->runtimeDir.'/resourceStats.lock';
        $lockBusy = false;
        $this->lockHandle = pmssLockFileAcquire($lockFile, true, 'c+', false, true, $lockBusy);
        if ($this->lockHandle !== false) { if ($lockBusy) { return false; } pmssLockHandleWritePid($this->lockHandle); return true; }
        logMessage(date('c').": Unable to open lock file {$lockFile} for resourceStats"); return true;
    }

    public function validateUser(string $user): bool
    {
        return $this->statsUserHasDataHomeAndPasswd($user, $user);
    }

    /** Process and persist resource statistics for a single user. */
    public function processUser(string $user, array $compareTimes): void
    {
        $logPrefix = date('c').': ';
        $loadedData = $this->loadStatsDataLines($user, [$this->stats, 'getData'], $logPrefix);
        if ($loadedData === null) {
            return;
        }
        $dataLines = $loadedData['data_lines'];

        $results = $this->stats->collectWindowResultsFromData(
            $dataLines,
            $compareTimes,
            function (string $line) use ($logPrefix, $user): void {
                $this->log($logPrefix."Parsing line failed for {$user}, line: {$line}");
            }
        );
        if ($results === null) {
            $this->log($logPrefix."No valid samples for {$user}");
            return;
        }

        $data = ['daily' => $results['daily']];
        foreach ($results['raw'] + ['memory' => $results['memory'], 'tasks' => $results['tasks']] as $metric => $values) {
            $data[$metric] = ['raw' => $values];
        }
        $data['memory']['current'] = $results['current_memory'];
        foreach (pmssResourceMemoryBreakdownFieldMap('current_memory_') as $field => $resultKey) {
            if (isset($results[$resultKey]) && is_numeric($results[$resultKey])) {
                $data['memory'][$field] = (float) $results[$resultKey];
            }
        }
        $data['tasks']['current'] = $results['current_tasks'];

        $this->ensureRuntime();
        if (!$this->save($user, $data, $logPrefix)) {
            return;
        }
        $this->log($logPrefix."Resource stats for {$user} saved, month read bytes: {$results['raw']['io_read']['month']}");
    }

    /** Persist user resource data to home directory and runtime cache. */
    private function save(string $user, array $data, string $logPrefix): bool
    {
        $serialized = serialize($data);
        $homePath = $this->homeDir.'/'.$user;

        $targets = [[$this->statsDir.'/'.$user, 'root', 0600, false]];
        is_dir($homePath) && array_unshift($targets, [$homePath.'/.resourceData', $user, 0640, true]);
        return pmssManagedSerializedTargetsWrite($serialized, $targets, function (string $path) use ($logPrefix, $user): void { $this->log($logPrefix."Failed to write resource stats for {$user} at {$path}"); });
    }
}
