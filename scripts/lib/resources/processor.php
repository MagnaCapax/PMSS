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

    /** Hold the cron spawn lock while detached workers start. */
    public function beforeSpawn(): bool
    {
        $lockFile = $this->runtimeDir.'/resourceStats.lock';
        $lockBusy = false;
        $this->lockHandle = pmssLockFileAcquire($lockFile, true, 'c+', false, true, $lockBusy);
        if ($this->lockHandle === false) {
            if ($lockBusy) {
                return false;
            }
            ($this->logger)(date('c').": Unable to open lock file {$lockFile} for resourceStats");
            return true;
        }
        if (!pmssLockHandleWritePid($this->lockHandle)) {
            pmssLockHandleRelease($this->lockHandle);
            $this->lockHandle = false;
            ($this->logger)(date('c').": Unable to record lock pid in {$lockFile} for resourceStats; continuing without spawn lock");
        }
        return true;
    }

    /** Process and persist resource statistics for a single user. */
    public function processUser(string $user, array $compareTimes): void
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
        if (!pmssManagedSerializedTargetsWrite($serialized, $targets, function (string $path) use ($logPrefix, $user): void { ($this->logger)($logPrefix."Failed to write resource stats for {$user} at {$path}"); })) {
            return;
        }
        ($this->logger)($logPrefix."Resource stats for {$user} saved, month read bytes: {$data['io_read']['raw']['month']}");
    }
}
