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
    // Aggregate in the cron process so resourceStats.lock covers every account (ADR 0049).
    protected $detachedWorkers = false;
    /** @var string */
    private $runtimeDir;
    /** @var string */
    private $statsDir;
    /** @var callable */
    private $logger;
    /** @var resource|false|null */
    private $lockHandle;
    /** @var array{0:int,1:string,2:float} accounts processed, slowest account and its seconds */
    private $runTiming = [0, '', 0.0];

    public function __construct(resourceStatistics $stats, array $paths = [])
    {
        parent::__construct($paths, 'resource_dir', 'PMSS_RESOURCE_DIR', '/var/log/pmss/resources', '/var/log/pmss/resourceStats.log');
        $this->runtimeDir = pmssDirPathResolve($paths['runtime_dir'] ?? null, 'PMSS_RUNTIME_DIR', '/var/run/pmss');
        $this->statsDir = pmssDirPathNormalize((string) ($paths['stats_dir'] ?? ($this->runtimeDir.'/resourceStats')));
        $this->stats = $stats;
        $this->logger = $paths['logger'] ?? 'logMessage';
    }

    /** Ensure runtime directories exist before writing. */
    public function ensureRuntime(): void
    {
        pmssManagedDirsEnsure([$this->runtimeDir => 0755, $this->statsDir => 0600], function (string $dir): void { ($this->logger)(date('c').": Unable to prepare resource runtime directory {$dir}"); });
    }

    /** Ensure resource runtime paths are ready before any CLI mode runs. */
    protected function beforeRunCli(): void
    {
        $this->ensureRuntime();
    }

    /** Aggregate every account under the run lock and log one summary line per run. */
    public function runCli(array $argv, string $scriptPath): int
    {
        $runStart = microtime(true);
        $this->runTiming = [0, '', 0.0];
        $rc = parent::runCli($argv, $scriptPath);
        if (!isset($argv[1]) && $this->runTiming[0] > 0) {
            // The run is the sum of the accounts; this shows how close it is to the 30-minute cadence.
            ($this->logger)(sprintf('%s: resourceStats processed %d accounts in %.1fs; slowest %s %.1fs',
                date('c'), $this->runTiming[0], microtime(true) - $runStart, $this->runTiming[1], $this->runTiming[2]));
        }
        return $rc;
    }

    /** Take the run lock; the handle stays open until the cron process exits. */
    public function beforeSpawn(): bool
    {
        $lockFile = $this->runtimeDir.'/resourceStats.lock';
        $lockBusy = false;
        $this->lockHandle = pmssLockFileAcquire($lockFile, true, 'c+', false, true, $lockBusy);
        if ($this->lockHandle === false) {
            if ($lockBusy) {
                ($this->logger)(date('c').": resourceStats lock {$lockFile} is held by an earlier run; skipping this run");
                return false;
            }
            ($this->logger)(date('c').": Unable to open lock file {$lockFile} for resourceStats");
            return true;
        }
        if (!pmssLockHandleWritePid($this->lockHandle)) {
            pmssLockHandleRelease($this->lockHandle);
            $this->lockHandle = false;
            ($this->logger)(date('c').": Unable to record lock pid in {$lockFile} for resourceStats; continuing without run lock");
        }
        // Accounts run in sorted order, so a run with this line but no summary hung on the account after the last one saved.
        ($this->logger)(date('c').': resourceStats run started, pid '.getmypid().($this->lockHandle === false ? ' (without run lock)' : ''));
        return true;
    }

    /** Process and persist resource statistics for a single user. */
    public function processUser(string $user, array $compareTimes): void
    {
        $userStart = microtime(true);
        try {
            $this->processUserStats($user, $compareTimes);
        } finally {
            $seconds = microtime(true) - $userStart;
            ++$this->runTiming[0];
            if ($seconds >= $this->runTiming[2]) {
                $this->runTiming = [$this->runTiming[0], $user, $seconds];
            }
        }
    }

    private function processUserStats(string $user, array $compareTimes): void
    {
        $logPrefix = date('c').': ';
        $loadedData = $this->loadStatsDataLines($user, [$this->stats, 'getData'], $logPrefix);
        if ($loadedData === null) {
            return;
        }
        $data = $this->stats->collectWindowResultsFromData(
            $loadedData,
            $compareTimes,
            function (string $line) use ($logPrefix, $user): void {
                ($this->logger)($logPrefix."Parsing line failed for {$user}, line: {$line}");
            }
        );
        if ($data === null) {
            ($this->logger)($logPrefix."No valid samples for {$user}");
            return;
        }

        $this->ensureRuntime();
        // Persist the same payload to the user home and runtime cache before reporting success.
        $serialized = serialize($data);
        $homePath = $this->homeDir.'/'.$user;
        $targets = [[$this->statsDir.'/'.$user, 'root', 0600, false]];
        is_dir($homePath) && array_unshift($targets, [$homePath.'/.resourceData', $user, 0640, true]);
        if (!pmssManagedSerializedTargetsWrite($serialized, $targets, function (string $path, string $failure = '') use ($logPrefix, $user): void { ($this->logger)($logPrefix."Failed to write resource stats for {$user} at {$path}".($failure === '' ? '' : ' ('.$failure.')')); })) {
            return;
        }
        ($this->logger)($logPrefix."Resource stats for {$user} saved, month read bytes: {$data['io_read']['raw']['month']}");
    }
}
