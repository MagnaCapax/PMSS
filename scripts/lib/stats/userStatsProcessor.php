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

    public function discoverUsers(): array { return pmssFileBasenamesDiscover($this->statsDataDir.'/*'); }

    public function spawnWorkers(string $scriptPath, array $users): void { pmssUserWorkersSpawnDetached($scriptPath, $users, $this->workerLogPath); }

    public function runCli(array $argv, string $scriptPath): int
    {
        $this->beforeRunCli();
        return pmssStatsProcessorRunCli(
            $argv,
            $scriptPath,
            [$this, 'validateUser'],
            [$this, 'processUser'],
            [$this, 'discoverUsers'],
            [$this, 'spawnWorkers'],
            [$this, 'beforeSpawn']
        );
    }

    protected function beforeRunCli(): void {}

    public function beforeSpawn(): bool { return true; }

    protected function statsUserHasDataHomeAndPasswd(string $dataUser, string $homeUser): bool
    {
        return is_readable($this->statsDataDir.'/'.$dataUser)
            && is_dir($this->homeDir.'/'.$homeUser)
            && pmssPasswdFileHasUser($this->passwdFile, $homeUser);
    }

    /** @return array{data_lines:string,records:array<int,string>}|null */
    protected function loadStatsDataLines(string $user, callable $loadData, string $logPrefix, bool $trimData = false): ?array { return pmssStatsProcessorDataLinesLoad($user, [$this, 'validateUser'], $loadData, $logPrefix, $trimData); }

    abstract public function validateUser(string $user): bool;

    abstract public function processUser(string $user, array $compareTimes): void;
}
