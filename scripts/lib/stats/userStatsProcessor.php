<?php
/**
 * Shared user-scoped stats processor plumbing.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime.php';

abstract class PmssUserStatsProcessor
{
    protected $statsDataDir;
    protected $homeDir;
    protected $passwdFile;
    private $workerLogPath;

    protected function __construct(array $paths, string $dirKey, string $envKey, string $defaultDir, string $workerLogPath)
    {
        $this->statsDataDir = pmssDirPathResolve($paths[$dirKey] ?? null, $envKey, $defaultDir);
        $this->homeDir = pmssDirPathResolve($paths['home_dir'] ?? null, 'PMSS_HOME_DIR', '/home');
        $this->passwdFile = $paths['passwd_file'] ?? getenv('PMSS_PASSWD_FILE') ?: '/etc/passwd';
        $this->workerLogPath = $workerLogPath;
    }

    public function discoverUsers(): array { $items = array_map('basename', array_filter(glob($this->statsDataDir.'/*') ?: [], 'is_file')); sort($items, SORT_NATURAL | SORT_FLAG_CASE); return $items; }

    public function spawnWorkers(string $scriptPath, array $users): void { $script = escapeshellarg($scriptPath); foreach ($users as $user) passthru("nohup {$script} ".escapeshellarg($user)." >> ".escapeshellarg($this->workerLogPath)." 2>&1 &"); }

    public function runCli(array $argv, string $scriptPath): int
    {
        $this->beforeRunCli();
        if (isset($argv[1])) { $user = self::sanitizeUserArg($argv[1]); if (!$this->validateUser($user)) { echo "Invalid user specified: {$user}\n"; return 0; } $this->processUser($user, pmssStatsCompareTimesBuild()); return 0; }
        if (!$this->beforeSpawn()) return 0;
        if (empty($users = $this->discoverUsers())) { echo "No users in this system!\n"; return 0; }
        $this->spawnWorkers($scriptPath, $users); return 0;
    }

    protected function beforeRunCli(): void {}

    public function beforeSpawn(): bool { return true; }

    private static function sanitizeUserArg(string $value): string { return (string) preg_replace('/[^a-zA-Z0-9-_]/', '', $value); }

    protected function statsUserHasDataHomeAndPasswd(string $dataUser, string $homeUser): bool
    {
        return is_readable($this->statsDataDir.'/'.$dataUser)
            && is_dir($this->homeDir.'/'.$homeUser)
            && self::passwdFileHasUser($this->passwdFile, $homeUser);
    }

    private static function passwdFileHasUser(string $passwdFile, string $user): bool { return is_string($passwd = @file_get_contents($passwdFile)) && preg_match('/^'.preg_quote($user, '/').':/m', $passwd) === 1; }

    /** @return array{data_lines:string,records:array<int,string>}|null */
    protected function loadStatsDataLines(string $user, callable $loadData, string $logPrefix, bool $trimData = false): ?array { if (!$this->validateUser($user)) { logMessage($logPrefix."Invalid user {$user}"); return null; } $dataLines = (string) $loadData($user, (int) ((35 * 24 * 60) / 5)); $trimData && $dataLines = trim($dataLines); if ($dataLines === '') { logMessage($logPrefix."No data for user {$user}"); return null; } if (count($records = array_filter(explode("\n", $dataLines))) < 2) { logMessage($logPrefix."Too little data for {$user}"); return null; } return ['data_lines' => $dataLines, 'records' => $records]; }

    abstract public function validateUser(string $user): bool;

    abstract public function processUser(string $user, array $compareTimes): void;
}
