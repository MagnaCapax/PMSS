<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TrafficTestCase.php';
require_once dirname(__DIR__, 2).'/traffic/processor.php';

class StubTrafficStatistics extends \trafficStatistics
{
    use TrafficStatisticsStubTrait;
}

class SpyTrafficStatsProcessor extends \TrafficStatsProcessor
{
    public array $usersToDiscover = [];
    public array $spawnCalls = [];

    public function discoverUsers(): array
    {
        return $this->usersToDiscover;
    }

    public function spawnWorkers(string $scriptPath, array $users): void
    {
        $this->spawnCalls[] = [$scriptPath, $users];
    }
}

class TrafficStatsProcessorTest extends TrafficTestCase
{
    public function testSharedTrafficAmountFormatter(): void
    {
        $formatted = array_map('pmssTrafficFormatAmount', [
            '15min' => 100,
            'hour'  => 2048,
            'day'   => 2048 * 2048,
        ]);
        $this->assertEquals('100MiB', $formatted['15min']);
        $this->assertTrue(strpos($formatted['hour'], 'GiB') !== false);
        $this->assertTrue(strpos($formatted['day'], 'TiB') !== false);
    }

    public function testSharedTrafficAmountFormatterPreservesThresholdBoundaries(): void
    {
        $formatted = array_map('pmssTrafficFormatAmount', [
            'exact_gib' => 1024,
            'over_gib' => 1025,
            'exact_tib' => 1024 * 1024,
            'over_tib' => (1024 * 1024) + 1,
        ]);

        $this->assertEquals('1024MiB', $formatted['exact_gib']);
        $this->assertEquals('1GiB', $formatted['over_gib']);
        $this->assertEquals('1024GiB', $formatted['exact_tib']);
        $this->assertEquals('1TiB', $formatted['over_tib']);
    }

    public function testProcessUserPersistsData(): void
    {
        $stub = new StubTrafficStatistics();
        [$paths, $processor] = $this->makeTrafficProcessorFixture($stub);

        $user = 'alice';
        $this->createTrafficUser($paths, $user);
        $stub->map[$user] = $this->makeTrafficUsageLines([
            100 => 1048576,
            86400 => 1048576,
        ]);

        $compare = \pmssStatsCompareTimesBuild();
        $processor->processUser($user, $compare);

        $this->assertTrue(isset($stub->saved[$user]));
        $this->assertTrue(isset($stub->saved[$user]['raw']['day']));
        $this->assertTrue(!isset($stub->saved[$user]['display']));
    }

    public function testValidateUserAcceptsLocalnetSuffix(): void
    {
        [$paths, $processor] = $this->makeTrafficProcessorFixture(new StubTrafficStatistics());

        $user = 'alice';
        $this->createTrafficUser($paths, $user);
        $localnetUser = $this->markTrafficUserLocalnet($paths, $user);

        $this->assertTrue($processor->validateUser($localnetUser));
    }

    public function testProcessUserPersistsLocalnetData(): void
    {
        $stub = new StubTrafficStatistics();
        [$paths, $processor] = $this->makeTrafficProcessorFixture($stub);

        $user = 'alice';
        $this->createTrafficUser($paths, $user);
        $localnetUser = $this->markTrafficUserLocalnet($paths, $user);
        $stub->map[$localnetUser] = $this->makeTrafficUsageLines([
            120 => 1048576,
            3600 => 1048576,
        ]);

        $compare = \pmssStatsCompareTimesBuild();
        $processor->processUser($localnetUser, $compare);

        $this->assertTrue(isset($stub->saved[$localnetUser]));
        $this->assertTrue(isset($stub->saved[$localnetUser]['raw']['day']));
        $this->assertTrue(!isset($stub->saved[$localnetUser]['display']));
    }

    public function testRunCliProcessesWorkerUser(): void
    {
        $stub = new StubTrafficStatistics();
        [$paths, $processor] = $this->makeTrafficProcessorFixture($stub, SpyTrafficStatsProcessor::class);
        $user = 'alice';

        $this->createTrafficUser($paths, $user);
        $stub->map[$user] = $this->makeTrafficUsageLines([
            100 => 1048576,
            86400 => 1048576,
        ]);

        $this->assertEquals(0, $processor->runCli(['/scripts/cron/trafficStats.php', 'a!li@ce'], '/scripts/cron/trafficStats.php'));
        $this->assertTrue(isset($stub->saved[$user]));
        $this->assertEquals([], $processor->spawnCalls);
    }

    public function testRunCliReportsInvalidWorkerUser(): void
    {
        $stub = new StubTrafficStatistics();
        [, $processor] = $this->makeTrafficProcessorFixture($stub, SpyTrafficStatsProcessor::class);

        list($result, $output) = $this->pmssCaptureStdout(function () use ($processor): int { return $processor->runCli(['/scripts/cron/trafficStats.php', 'ghost'], '/scripts/cron/trafficStats.php'); });

        $this->assertEquals(0, $result);
        $this->assertStringContainsString("Invalid user specified: ghost\n", $output);
        $this->assertEquals([], $stub->saved);
        $this->assertEquals([], $processor->spawnCalls);
    }

    public function testRunCliPrintsNoUsersMessageWithoutDiscoveredUsers(): void
    {
        [, $processor] = $this->makeTrafficProcessorFixture(new StubTrafficStatistics(), SpyTrafficStatsProcessor::class);

        list($result, $output) = $this->pmssCaptureStdout(function () use ($processor): int { return $processor->runCli(['/scripts/cron/trafficStats.php'], '/scripts/cron/trafficStats.php'); });

        $this->assertEquals(0, $result);
        $this->assertStringContainsString("No users in this system!\n", $output);
        $this->assertEquals([], $processor->spawnCalls);
    }

    public function testRunCliSpawnsWorkersForDiscoveredUsers(): void
    {
        [, $processor] = $this->makeTrafficProcessorFixture(new StubTrafficStatistics(), SpyTrafficStatsProcessor::class);
        $processor->usersToDiscover = ['alice', 'bob'];

        $this->assertEquals(0, $processor->runCli(['/scripts/cron/trafficStats.php'], '/scripts/cron/trafficStats.php'));
        $this->assertEquals([['/scripts/cron/trafficStats.php', ['alice', 'bob']]], $processor->spawnCalls);
    }
}
