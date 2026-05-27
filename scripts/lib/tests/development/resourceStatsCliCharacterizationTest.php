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

final class resourceStatsCliCharacterizationTest extends TestCase
{
    public function testCliFlowSharesProcessorHelpers(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/cron/resourceStats.php', 'pmssRunCliProcessorEntrypoint(__FILE__, new ResourceStatsProcessor(new resourceStatistics()))');
        $this->pmssAssertRepoFileContainsString('scripts/lib/runtime.php', 'function pmssRunCliProcessorEntrypoint(string $scriptPath, object $processor): void');
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

        ob_start();
        $userRc = $processor->runCli(['resourceStats.php', 'alice!'], '/scripts/cron/resourceStats.php');
        $userOutput = ob_get_clean();

        $this->assertSame(0, $userRc);
        $this->assertSame('', $userOutput);
        $this->assertEquals(['alice', ['month', 'week', 'day', 'hour', '15min']], $processor->processed);
        $this->assertEquals([], $processor->spawned);

        ob_start();
        $spawnRc = $processor->runCli(['resourceStats.php'], '/scripts/cron/resourceStats.php');
        $spawnOutput = ob_get_clean();

        $this->assertSame(0, $spawnRc);
        $this->assertSame('', $spawnOutput);
        $this->assertEquals(['/scripts/cron/resourceStats.php', ['alice', 'bob']], $processor->spawned);
    }

    public function testStatsProcessorCliKeepsInvalidUserOutput(): void
    {
        $processor = new ResourceStatsCliHarness([], []);

        ob_start();
        $rc = $processor->runCli(['resourceStats.php', 'bad!'], '/scripts/cron/resourceStats.php');
        $output = ob_get_clean();

        $this->assertSame(0, $rc);
        $this->assertSame("Invalid user specified: bad\n", $output);
    }
}
