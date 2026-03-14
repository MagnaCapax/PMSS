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
        $this->resourceDir = rtrim($paths['resource_dir'] ?? getenv('PMSS_RESOURCE_DIR') ?: '/var/log/pmss/resources', '/');
        $this->homeDir = rtrim($paths['home_dir'] ?? getenv('PMSS_HOME_DIR') ?: '/home', '/');
        $this->runtimeDir = rtrim($paths['runtime_dir'] ?? getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/');
        $this->statsDir = rtrim($paths['stats_dir'] ?? $this->runtimeDir.'/resourceStats', '/');
        $this->passwdFile = $paths['passwd_file'] ?? getenv('PMSS_PASSWD_FILE') ?: '/etc/passwd';

        $this->stats = $stats;
    }

    /** Ensure runtime directories exist before writing. */
    public function ensureRuntime(): void
    {
        foreach ([$this->runtimeDir => 0755, $this->statsDir => 0600] as $dir => $mode) {
            is_dir($dir) || @mkdir($dir, $mode, true);
        }
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
            passthru("nohup {$script} ".escapeshellarg($user)." >> /var/log/pmss/resourceStats.log 2>&1 &");
        }
    }

    public function validateUser(string $user): bool
    {
        if (!is_readable($this->resourceDir.'/'.$user)) {
            return false;
        }

        $passwd = @file_get_contents($this->passwdFile);
        return $passwd !== false
            && preg_match('/^'.preg_quote($user, '/').':/m', $passwd) === 1
            && is_dir($this->homeDir.'/'.$user);
    }

    /** Process and persist resource statistics for a single user. */
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

        if (count($resourceData = array_filter(explode("\n", $dataLines))) < 2) {
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
        $metricData = $results['raw'] + [
            'memory' => $results['memory'],
            'tasks' => $results['tasks'],
        ];
        foreach (['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'memory', 'tasks', 'ram_hours'] as $metric) {
            $data[$metric] = [
                'raw' => $metricData[$metric],
                'display' => $this->formatMetricDisplay($metric, $metricData[$metric]),
            ];
        }
        $data['memory']['current'] = $results['current_memory'];
        $data['tasks']['current'] = $results['current_tasks'];
        $data['daily'] = $results['daily'];

        $this->ensureRuntime();
        $this->save($user, $data);
        logMessage(date('c').": Resource stats for {$user} saved, month read bytes: {$results['raw']['io_read']['month']}");
    }

    /** Persist user resource data to home directory and runtime cache. */
    private function save(string $user, array $data): void
    {
        $serialized = serialize($data);
        $homePath = $this->homeDir.'/'.$user;

        if (is_dir($homePath)) {
            $userPath = $homePath.'/.resourceData';
            $this->setImmutable($userPath, false);
            $this->writeAtomic($userPath, $serialized);
            @chown($userPath, 'root');
            $user !== '' && @chgrp($userPath, $user);
            @chmod($userPath, 0640);
            $this->setImmutable($userPath, true);
        }

        $runtimePath = $this->statsDir.'/'.$user;
        $this->writeAtomic($runtimePath, $serialized);
        @chown($runtimePath, 'root');
        @chgrp($runtimePath, 'root');
        @chmod($runtimePath, 0600);
    }

    /**
     * Write payloads atomically to avoid partial truncation on interruption.
     */
    private function writeAtomic(string $path, string $payload): void
    {
        $tmp = $path.'.tmp.'.getmypid().'.'.mt_rand(1000, 9999);
        if (@file_put_contents($tmp, $payload) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /**
     * Toggle immutable bit when supported (best-effort).
     */
    private function setImmutable(string $path, bool $enable): void
    {
        if (!is_file($path)) {
            return;
        }
        static $chattrPath = null;
        if ($chattrPath === null) {
            $chattrPath = '';
            foreach (['/usr/bin/chattr', '/bin/chattr'] as $candidate) {
                if (is_executable($candidate)) {
                    $chattrPath = $candidate;
                    break;
                }
            }
        }
        if ($chattrPath === '') {
            return;
        }
        $flag = $enable ? '+i' : '-i';
        @exec($chattrPath.' '.$flag.' '.escapeshellarg($path).' 2>/dev/null');
    }

    private function formatMetricDisplay(string $metric, array $rawTotals): array
    {
        $formatted = [];
        $byteMetric = $metric === 'io_read' || $metric === 'io_write' || $metric === 'memory';
        $byteDivisors = [1099511627776 => 'TiB', 1073741824 => 'GiB', 1048576 => 'MiB'];
        foreach ($rawTotals as $label => $value) {
            $number = (float) $value;
            if ($metric === 'cpu') {
                $seconds = $number / 1000000000;
                $formatted[$label] = $seconds >= 3600
                    ? round($seconds / 3600, 2).'h'
                    : ($seconds >= 60 ? round($seconds / 60, 2).'m' : round($seconds, 2).'s');
                continue;
            }
            if (!$byteMetric) {
                $formatted[$label] = $metric === 'ram_hours' ? round($number, 2).'GB-hrs' : (string) round($number, 2);
                continue;
            }
            foreach ($byteDivisors as $divisor => $suffix) {
                if ($number > $divisor) {
                    $formatted[$label] = round($number / $divisor, 2).$suffix;
                    continue 2;
                }
            }
            $formatted[$label] = round($number / 1024, 2).'KiB';
        }
        return $formatted;
    }
}
