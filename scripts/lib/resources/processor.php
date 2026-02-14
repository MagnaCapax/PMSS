<?php
/**
 * Orchestrates per-user resource statistics calculations.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../resources.php';
require_once __DIR__.'/storage.php';
require_once __DIR__.'/accumulator.php';
require_once __DIR__.'/formatter.php';
require_once __DIR__.'/userScope.php';
class ResourceStatsProcessor
{
    /** @var resourceStatistics */
    private $stats;
    /** @var ResourceStorage */
    private $storage;
    /** @var ResourceStatsFormatter */
    private $formatter;
    /** @var ResourceStatsUserScope */
    private $userScope;

    public function __construct(resourceStatistics $stats, array $paths = [])
    {
        $resourceDir = rtrim($paths['resource_dir'] ?? getenv('PMSS_RESOURCE_DIR') ?: '/var/log/pmss/resources', '/');
        $homeDir = rtrim($paths['home_dir'] ?? getenv('PMSS_HOME_DIR') ?: '/home', '/');
        $runtimeDir = rtrim($paths['runtime_dir'] ?? getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/');
        $passwdFile = $paths['passwd_file'] ?? getenv('PMSS_PASSWD_FILE') ?: '/etc/passwd';

        $this->stats = $stats;
        $this->storage = new ResourceStorage([
            'home_dir'    => $homeDir,
            'runtime_dir' => $runtimeDir,
            'stats_dir'   => $runtimeDir.'/resourceStats',
        ]);
        $this->formatter = new ResourceStatsFormatter();
        $this->userScope = new ResourceStatsUserScope($resourceDir, $homeDir, $passwdFile);
    }

    public function ensureRuntime(): void
    {
        $this->storage->ensureRuntime();
    }

    public function buildCompareTimes(): array
    {
        $now = time();
        return [
            'month' => $now - (30 * 24 * 60 * 60),
            'week'  => $now - (7 * 24 * 60 * 60),
            'day'   => $now - (24 * 60 * 60),
            'hour'  => $now - (60 * 60),
            '15min' => $now - (15 * 60),
        ];
    }

    public function detectWorkerUser(array $argv): ?string
    {
        return isset($argv[1]) ? $this->userScope->sanitizeUser($argv[1]) : null;
    }

    public function discoverUsers(): array
    {
        return $this->userScope->discoverUsers();
    }

    public function spawnWorkers(string $scriptPath, array $users): void
    {
        $this->userScope->spawnWorkers($scriptPath, $users);
    }

    public function validateUser(string $user): bool
    {
        return $this->userScope->validateUser($user);
    }

    /** Process and persist resource statistics for a single user. */
    public function processUser(string $user, array $compareTimes): void
    {
        if (!$this->userScope->validateUser($user)) {
            logMessage(date('c').": Invalid user {$user}");
            return;
        }

        $dataLines = $this->stats->getData($user, (int) ((35 * 24 * 60) / 5));
        if (trim($dataLines) === '') {
            logMessage(date('c').": No data for user {$user}");
            return;
        }

        $resourceData = array_filter(explode("\n", trim($dataLines)));
        if (count($resourceData) < 2) {
            logMessage(date('c').": Too little data for {$user}");
            return;
        }

        $accumulator = new ResourceStatsAccumulator($compareTimes);
        foreach ($resourceData as $line) {
            $parsed = $this->stats->parseLine($line);
            if ($parsed === false) {
                logMessage(date('c').": Parsing line failed for {$user}, line: {$line}");
                continue;
            }
            $accumulator->addSample($parsed);
        }

        if (!$accumulator->hasSamples()) {
            logMessage(date('c').": No valid samples for {$user}");
            return;
        }

        $results = $accumulator->results();
        $rawTotals = $results['raw'];

        $data = [
            'io_read' => [
                'raw'     => $rawTotals['io_read'],
                'display' => $this->formatter->formatBytesDisplay($rawTotals['io_read']),
            ],
            'io_write' => [
                'raw'     => $rawTotals['io_write'],
                'display' => $this->formatter->formatBytesDisplay($rawTotals['io_write']),
            ],
            'cpu' => [
                'raw'     => $rawTotals['cpu'],
                'display' => $this->formatter->formatCpuDisplay($rawTotals['cpu']),
            ],
            'memory' => [
                'raw'     => $results['memory'],
                'display' => $this->formatter->formatBytesDisplay($results['memory']),
                'current' => $results['current_memory'],
            ],
            'tasks' => [
                'raw'     => $results['tasks'],
                'display' => $this->formatter->formatCountDisplay($results['tasks']),
                'current' => $results['current_tasks'],
            ],
            'ram_hours' => [
                'raw'     => $rawTotals['ram_hours'],
                'display' => $this->formatter->formatRamHoursDisplay($rawTotals['ram_hours']),
            ],
            'daily' => $results['daily'],
        ];

        $this->stats->saveUserResources($user, $data);
        logMessage(date('c').": Resource stats for {$user} saved, month read bytes: {$rawTotals['io_read']['month']}");
    }
}
