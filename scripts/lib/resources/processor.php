<?php
/**
 * Orchestrates per-user resource statistics calculations.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../resources.php';

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
    /** @var callable */
    private $logger;

    public function __construct(resourceStatistics $stats, array $paths = [])
    {
        $this->resourceDir = pmssDirPathResolve($paths['resource_dir'] ?? null, 'PMSS_RESOURCE_DIR', '/var/log/pmss/resources');
        $this->homeDir = pmssDirPathResolve($paths['home_dir'] ?? null, 'PMSS_HOME_DIR', '/home');
        $this->runtimeDir = pmssDirPathResolve($paths['runtime_dir'] ?? null, 'PMSS_RUNTIME_DIR', '/var/run/pmss');
        $this->statsDir = pmssDirPathNormalize((string) ($paths['stats_dir'] ?? ($this->runtimeDir.'/resourceStats')));
        $this->passwdFile = $paths['passwd_file'] ?? getenv('PMSS_PASSWD_FILE') ?: '/etc/passwd';
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
        $runtimeDir = $this->runtimeDir;
        $lockHandle = null;
        return pmssStatsProcessorRunCli(
            $argv,
            $scriptPath,
            [$this, 'validateUser'],
            [$this, 'processUser'],
            [$this, 'discoverUsers'],
            [$this, 'spawnWorkers'],
            static function () use ($runtimeDir, &$lockHandle): bool {
                $lockFile = $runtimeDir.'/resourceStats.lock';
                $lockBusy = false;
                $lockHandle = pmssLockFileAcquire($lockFile, true, 'c+', false, true, $lockBusy);
                if ($lockHandle !== false) { if ($lockBusy) { return false; } pmssLockHandleWritePid($lockHandle); return true; }
                logMessage(date('c').": Unable to open lock file {$lockFile} for resourceStats"); return true;
            }
        );
    }

    public function validateUser(string $user): bool
    {
        return is_readable($this->resourceDir.'/'.$user)
            && pmssPasswdFileHasUser($this->passwdFile, $user)
            && is_dir($this->homeDir.'/'.$user);
    }

    /** Process and persist resource statistics for a single user. */
    public function processUser(string $user, array $compareTimes): void
    {
        $logPrefix = date('c').': ';
        $loadedData = pmssStatsProcessorDataLinesLoad(
            $user,
            [$this, 'validateUser'],
            [$this->stats, 'getData'],
            $logPrefix
        );
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

        $data = pmssResourceStoredPayloadBuild($results);

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
