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

class ResourceStatsProcessor
{
    /** @var resourceStatistics */
    private $stats;
    /** @var ResourceStorage */
    private $storage;
    /** @var string */
    private $resourceDir;
    /** @var string */
    private $homeDir;
    /** @var string */
    private $passwdFile;

    public function __construct(resourceStatistics $stats, array $paths = [])
    {
        $this->resourceDir = rtrim($paths['resource_dir'] ?? getenv('PMSS_RESOURCE_DIR') ?: '/var/log/pmss/resources', '/');
        $this->homeDir = rtrim($paths['home_dir'] ?? getenv('PMSS_HOME_DIR') ?: '/home', '/');
        $runtimeDir = rtrim($paths['runtime_dir'] ?? getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/');
        $this->passwdFile = $paths['passwd_file'] ?? getenv('PMSS_PASSWD_FILE') ?: '/etc/passwd';

        $this->stats = $stats;
        $this->storage = new ResourceStorage([
            'home_dir'    => $this->homeDir,
            'runtime_dir' => $runtimeDir,
            'stats_dir'   => $runtimeDir.'/resourceStats',
        ]);
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
        return isset($argv[1]) ? preg_replace('/[^a-zA-Z0-9-_]/', '', $argv[1]) : null;
    }

    public function discoverUsers(): array
    {
        $users = array_map('basename', array_filter(glob($this->resourceDir.'/*'), 'is_file'));
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
        return $users;
    }

    public function spawnWorkers(string $scriptPath, array $users): void
    {
        $script = escapeshellarg($scriptPath);
        foreach ($users as $user) {
            $userArg = escapeshellarg($user);
            $command = "nohup {$script} {$userArg} >> /var/log/pmss/resourceStats.log 2>&1 &";
            passthru($command);
        }
    }

    public function validateUser(string $user): bool
    {
        $path = $this->resourceDir.'/'.$user;
        $homePath = $this->homeDir.'/'.$user;
        if (!is_readable($path)) {
            return false;
        }

        $passwd = @file_get_contents($this->passwdFile);
        return $passwd !== false
            && preg_match('/^'.preg_quote($user, '/').':/m', $passwd) === 1
            && is_dir($homePath);
    }

    /** Process and persist resource statistics for a single user. */
    public function processUser(string $user, array $compareTimes): void
    {
        if (!$this->validateUser($user)) {
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

        $data = [];
        foreach ([
            'io_read' => 'formatBytesDisplay',
            'io_write' => 'formatBytesDisplay',
            'io_read_ops' => 'formatRoundedDisplay',
            'io_write_ops' => 'formatRoundedDisplay',
            'cpu' => 'formatCpuDisplay',
        ] as $metric => $formatter) {
            $data[$metric] = [
                'raw' => $rawTotals[$metric],
                'display' => $this->{$formatter}($rawTotals[$metric]),
            ];
        }
        $data['memory'] = [
            'raw'     => $results['memory'],
            'display' => $this->formatBytesDisplay($results['memory']),
            'current' => $results['current_memory'],
        ];
        $data['tasks'] = [
            'raw'     => $results['tasks'],
            'display' => $this->formatRoundedDisplay($results['tasks']),
            'current' => $results['current_tasks'],
        ];
        $data['ram_hours'] = [
            'raw'     => $rawTotals['ram_hours'],
            'display' => $this->formatRoundedDisplay($rawTotals['ram_hours'], 2, 'GB-hrs'),
        ];
        $data['daily'] = $results['daily'];

        $this->storage->ensureRuntime();
        $this->storage->save($user, $data);
        logMessage(date('c').": Resource stats for {$user} saved, month read bytes: {$rawTotals['io_read']['month']}");
    }

    private function formatBytesDisplay(array $rawTotals): array
    {
        $formatted = [];
        foreach ($rawTotals as $label => $value) {
            $bytes = (float) $value;
            foreach ([1099511627776 => 'TiB', 1073741824 => 'GiB', 1048576 => 'MiB'] as $divisor => $suffix) {
                if (($bytes / $divisor) > 1) {
                    $formatted[$label] = round($bytes / $divisor, 2).$suffix;
                    continue 2;
                }
            }
            $formatted[$label] = round($bytes / 1024, 2).'KiB';
        }
        return $formatted;
    }

    private function formatCpuDisplay(array $rawTotals): array
    {
        $formatted = [];
        foreach ($rawTotals as $label => $value) {
            $seconds = $value / 1000000000;
            $formatted[$label] = $seconds >= 3600
                ? round($seconds / 3600, 2).'h'
                : ($seconds >= 60 ? round($seconds / 60, 2).'m' : round($seconds, 2).'s');
        }
        return $formatted;
    }

    private function formatRoundedDisplay(array $rawTotals, int $precision = 2, string $suffix = ''): array
    {
        $formatted = [];
        foreach ($rawTotals as $label => $value) {
            $formatted[$label] = (string) round((float) $value, $precision).$suffix;
        }
        return $formatted;
    }
}
