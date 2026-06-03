<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/stats/userStatsProcessor.php';

final class ResourceStatsCliHarness extends \PmssUserStatsProcessor
{
    /** @var array<int,string> */
    private $validUsers;
    /** @var array<int,string> */
    private $discoveredUsers;
    /** @var array<int,mixed> */
    public $processed = [];
    /** @var array<int,mixed> */
    public $spawned = [];

    public function __construct(array $validUsers, array $discoveredUsers)
    {
        parent::__construct(['resource_dir' => sys_get_temp_dir(), 'home_dir' => sys_get_temp_dir(), 'passwd_file' => '/dev/null'], 'resource_dir', 'PMSS_RESOURCE_DIR', sys_get_temp_dir(), '/tmp/pmss-test.log');
        $this->validUsers = $validUsers;
        $this->discoveredUsers = $discoveredUsers;
    }

    public function discoverUsers(): array { return $this->discoveredUsers; }
    public function spawnWorkers(string $scriptPath, array $users): void { $this->spawned = [$scriptPath, $users]; }
    public function validateUser(string $user): bool { return in_array($user, $this->validUsers, true); }
    public function processUser(string $user, array $compareTimes): void { $this->processed = [$user, array_keys($compareTimes)]; }
}

final class ResourceStatsSpawnHarness extends \PmssUserStatsProcessor
{
    /** @var array<int,string> */
    public $commands = [];
    /** @var array<int,int> */
    private $returnCodes;

    public function __construct(array $returnCodes = [])
    {
        parent::__construct(['resource_dir' => sys_get_temp_dir(), 'home_dir' => sys_get_temp_dir(), 'passwd_file' => '/dev/null'], 'resource_dir', 'PMSS_RESOURCE_DIR', sys_get_temp_dir(), '/tmp/pmss stats.log');
        $this->returnCodes = $returnCodes;
    }

    protected function runSpawnCommand(string $command): int
    {
        $this->commands[] = $command;
        return array_shift($this->returnCodes) ?? 0;
    }

    public function validateUser(string $user): bool { return true; }
    public function processUser(string $user, array $compareTimes): void {}
}

final class resourceStatsCliCharacterizationTest extends TestCase
{
    public function testCliFlowSharesProcessorHelpers(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/cron/resourceStats.php', 'pmssRunCliProcessorEntrypoint(__FILE__, new ResourceStatsProcessor(new resourceStatistics()))');
        $this->pmssAssertRepoFileContainsString('scripts/lib/runtime/cli.php', 'function pmssRunCliProcessorEntrypoint(string $scriptPath, object $processor): void');
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/resourceStats.php', "\$processor->spawnWorkers(\$_SERVER['argv'][0], \$users);");
        $this->pmssAssertRepoFileContainsString('scripts/lib/stats/userStatsProcessor.php', 'function runCli(array $argv, string $scriptPath): int');
        foreach (['scripts/lib/resources/processor.php', 'scripts/lib/traffic/processor.php'] as $path) {
            $this->pmssAssertRepoFileContainsString($path, 'extends PmssUserStatsProcessor');
            $this->pmssAssertRepoFileNotContainsString($path, "preg_match('/^'.preg_quote(");
        }

        $this->pmssAssertRepoFileContainsString('scripts/lib/user/add/preflight.php', 'pmssPasswdEntryLookup($userName) !== null');
    }

    public function testStatsProcessorCliKeepsUserAndSpawnContracts(): void
    {
        $processor = new ResourceStatsCliHarness(['alice'], ['alice', 'bob']);

        list($userRc, $userOutput) = $this->pmssCaptureStdout(function () use ($processor): int {
            return $processor->runCli(['resourceStats.php', 'alice!'], '/scripts/cron/resourceStats.php');
        });

        $this->assertSame(0, $userRc);
        $this->assertSame('', $userOutput);
        $this->assertEquals(['alice', ['month', 'week', 'day', 'hour', '15min']], $processor->processed);
        $this->assertEquals([], $processor->spawned);

        list($spawnRc, $spawnOutput) = $this->pmssCaptureStdout(function () use ($processor): int {
            return $processor->runCli(['resourceStats.php'], '/scripts/cron/resourceStats.php');
        });

        $this->assertSame(0, $spawnRc);
        $this->assertSame('', $spawnOutput);
        $this->assertEquals(['/scripts/cron/resourceStats.php', ['alice', 'bob']], $processor->spawned);
    }

    public function testStatsProcessorCliKeepsInvalidUserOutput(): void
    {
        $processor = new ResourceStatsCliHarness([], []);

        list($rc, $output) = $this->pmssCaptureStdout(function () use ($processor): int {
            return $processor->runCli(['resourceStats.php', 'bad!'], '/scripts/cron/resourceStats.php');
        });

        $this->assertSame(0, $rc);
        $this->assertSame("Invalid user specified: bad\n", $output);
    }

    public function testStatsProcessorSpawnBuildsEscapedDetachedCommands(): void
    {
        $processor = new ResourceStatsSpawnHarness();
        $processor->spawnWorkers('/tmp/worker script.php', ['alice', 'bob-localnet']);

        $this->assertEquals([
            'nohup '.escapeshellarg('/tmp/worker script.php').' '.escapeshellarg('alice').' >> '.escapeshellarg('/tmp/pmss stats.log').' 2>&1 &',
            'nohup '.escapeshellarg('/tmp/worker script.php').' '.escapeshellarg('bob-localnet').' >> '.escapeshellarg('/tmp/pmss stats.log').' 2>&1 &',
        ], $processor->commands);
    }

    public function testStatsProcessorSpawnLogsShellStartupFailure(): void
    {
        $processor = new ResourceStatsSpawnHarness([7]);

        list(, $output) = $this->pmssCaptureStdout(function () use ($processor): void {
            $processor->spawnWorkers('/tmp/worker.php', ['alice']);
        });

        $this->assertStringContainsString('Failed to start stats worker, rc=7', $output);
        $this->assertEquals(1, count($processor->commands));
    }
}
