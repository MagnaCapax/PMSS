<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TrafficTestCase.php';
require_once dirname(__DIR__, 2).'/traffic/processor.php';

class StubTrafficStatistics extends \trafficStatistics
{
    public array $map = [];
    public array $saved = [];

    public function getData($user, $timePeriod = 5050)
    {
        return $this->map[$user] ?? '';
    }

    public function saveUserTraffic($user, $data)
    {
        $this->saved[$user] = $data;
    }
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
    public function testSanitizeUser(): void
    {
        $processor = $this->makeProcessor();
        $this->assertEquals('alice-bob', $processor->sanitizeUser('alice!@#-bob'));
    }

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
        $paths = $this->makeTrafficPaths('pmss-traffic-', true);
        $processor = new \TrafficStatsProcessor($stub, $paths);

        $user = 'alice';
        $this->createTrafficUser($paths, $user);

        $now = time();
        $lines = [
            date('Y-m-d H:i:s', $now - 100).': 1048576',
            date('Y-m-d H:i:s', $now - 86400).': 1048576',
        ];
        $stub->map[$user] = implode("\n", $lines);

        $compare = $processor->buildCompareTimes();
        $processor->processUser($user, $compare);

        $this->assertTrue(isset($stub->saved[$user]));
        $this->assertTrue(isset($stub->saved[$user]['raw']['day']));
    }

    public function testValidateUserAcceptsLocalnetSuffix(): void
    {
        $paths = $this->makeTrafficPaths('pmss-traffic-', true);
        $processor = new \TrafficStatsProcessor(new StubTrafficStatistics(), $paths);

        $user = 'alice';
        $this->createTrafficUser($paths, $user);
        file_put_contents($paths['traffic_dir'].'/'.$user.'-localnet', 'seed');

        $this->assertTrue($processor->validateUser($user.'-localnet'));
    }

    public function testProcessUserPersistsLocalnetData(): void
    {
        $stub = new StubTrafficStatistics();
        $paths = $this->makeTrafficPaths('pmss-traffic-', true);
        $processor = new \TrafficStatsProcessor($stub, $paths);

        $user = 'alice';
        $this->createTrafficUser($paths, $user);
        file_put_contents($paths['traffic_dir'].'/'.$user.'-localnet', 'seed');

        $now = time();
        $lines = [
            date('Y-m-d H:i:s', $now - 120).': 1048576',
            date('Y-m-d H:i:s', $now - 3600).': 1048576',
        ];
        $stub->map[$user.'-localnet'] = implode("\n", $lines);

        $compare = $processor->buildCompareTimes();
        $processor->processUser($user.'-localnet', $compare);

        $this->assertTrue(isset($stub->saved[$user.'-localnet']));
        $this->assertTrue(isset($stub->saved[$user.'-localnet']['raw']['day']));
    }

    public function testRunCliProcessesWorkerUser(): void
    {
        $stub = new StubTrafficStatistics();
        $paths = $this->makeTrafficPaths('pmss-traffic-', true);
        $processor = new SpyTrafficStatsProcessor($stub, $paths);
        $user = 'alice';

        $this->createTrafficUser($paths, $user);
        $now = time();
        $stub->map[$user] = implode("\n", [
            date('Y-m-d H:i:s', $now - 100).': 1048576',
            date('Y-m-d H:i:s', $now - 86400).': 1048576',
        ]);

        $this->assertEquals(0, $processor->runCli(['/scripts/cron/trafficStats.php', $user], '/scripts/cron/trafficStats.php'));
        $this->assertTrue(isset($stub->saved[$user]));
        $this->assertEquals([], $processor->spawnCalls);
    }

    public function testRunCliReportsInvalidWorkerUser(): void
    {
        $stub = new StubTrafficStatistics();
        $processor = new SpyTrafficStatsProcessor($stub, $this->makeTrafficPaths('pmss-traffic-', true));

        ob_start();
        $result = $processor->runCli(['/scripts/cron/trafficStats.php', 'ghost'], '/scripts/cron/trafficStats.php');
        $output = (string) ob_get_clean();

        $this->assertEquals(0, $result);
        $this->assertStringContainsString("Invalid user specified: ghost\n", $output);
        $this->assertEquals([], $stub->saved);
        $this->assertEquals([], $processor->spawnCalls);
    }

    public function testRunCliPrintsNoUsersMessageWithoutDiscoveredUsers(): void
    {
        $processor = new SpyTrafficStatsProcessor(new StubTrafficStatistics(), $this->makeTrafficPaths('pmss-traffic-', true));

        ob_start();
        $result = $processor->runCli(['/scripts/cron/trafficStats.php'], '/scripts/cron/trafficStats.php');
        $output = (string) ob_get_clean();

        $this->assertEquals(0, $result);
        $this->assertStringContainsString("No users in this system!\n", $output);
        $this->assertEquals([], $processor->spawnCalls);
    }

    public function testRunCliSpawnsWorkersForDiscoveredUsers(): void
    {
        $processor = new SpyTrafficStatsProcessor(new StubTrafficStatistics(), $this->makeTrafficPaths('pmss-traffic-', true));
        $processor->usersToDiscover = ['alice', 'bob'];

        $this->assertEquals(0, $processor->runCli(['/scripts/cron/trafficStats.php'], '/scripts/cron/trafficStats.php'));
        $this->assertEquals([['/scripts/cron/trafficStats.php', ['alice', 'bob']]], $processor->spawnCalls);
    }

    private function makeProcessor(): \TrafficStatsProcessor
    {
        return new \TrafficStatsProcessor(new StubTrafficStatistics(), $this->makeTrafficPaths('pmss-traffic-', true));
    }
}
