<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TrafficTestCase.php';
require_once dirname(__DIR__, 2).'/traffic/processor.php';

class TrafficStatsProcessorTest extends TrafficTestCase
{
    public function testSharedTrafficFormatterCharacterization(): void
    {
        $formatted = array_map('pmssTrafficFormatAmount', [
            '15min' => 100,
            'hour' => 2048,
            'day' => 2048 * 2048,
            'exact_gib' => 1024,
            'over_gib' => 1025,
            'exact_tib' => 1024 * 1024,
            'over_tib' => (1024 * 1024) + 1,
        ]);

        $this->assertEquals([
            '15min' => '100MiB', 'hour' => '2GiB', 'day' => '4TiB',
            'exact_gib' => '1024MiB', 'over_gib' => '1GiB',
            'exact_tib' => '1024GiB', 'over_tib' => '1TiB',
        ], $formatted);
    }

    public function testProcessorSavesBaseAndLocalnetPayloads(): void
    {
        foreach ([
            ['offsets' => [100 => 1048576, 86400 => 1048576], 'localnet' => false],
            ['offsets' => [120 => 1048576, 3600 => 1048576], 'localnet' => true],
        ] as $case) {
            [$stub, $paths, $processor] = $this->makeTrafficProcessorStubFixture();

            $user = 'alice';
            $this->createTrafficUser($paths, $user);
            $user = $case['localnet'] ? $this->markTrafficUserLocalnet($paths, $user) : $user;
            $stub->map[$user] = $this->makeTrafficUsageLines($case['offsets']);

            $processor->processUser($user, \pmssStatsCompareTimesBuild());

            $this->assertTrue(isset($stub->saved[$user]));
            $this->assertTrue(isset($stub->saved[$user]['raw']['day']));
            $this->assertTrue(!isset($stub->saved[$user]['display']));
            if ($case['localnet']) {
                $this->assertTrue($processor->validateUser($user));
            }
        }
    }

    public function testDailyTotalsMatchMonthlyWindowAcrossTrafficModes(): void
    {
        $now = strtotime('2026-08-22 12:00:00');
        $monthSeconds = 30 * 24 * 60 * 60;
        $usageLines = $this->makeTrafficUsageLines([
            $monthSeconds + 1 => 1048576,
            $monthSeconds => 2 * 1048576,
            $monthSeconds - 300 => 4 * 1048576,
            28 * 24 * 60 * 60 => 8 * 1048576,
            60 => 16 * 1048576,
        ], $now);
        $expectedDaily = [
            date('Y/m/d', $now - $monthSeconds) => 6.0,
            date('Y/m/d', $now - (28 * 24 * 60 * 60)) => 8.0,
            date('Y/m/d', $now - 60) => 16.0,
        ];

        foreach (['egress', 'ingress'] as $mode) {
            $paths = $this->makeTrafficPaths('pmss-traffic-window-', true, ['traffic_mode' => $mode]);
            $this->createTrafficUser($paths, 'alice');
            $this->pmssWriteFile($paths['traffic_dir'].'/alice', $usageLines."\n");

            $processor = new \TrafficStatsProcessor(new \trafficStatistics($paths), $paths);
            $processor->processUser('alice', \pmssStatsCompareTimesBuild($now));

            $dataPath = \pmssTrafficDataPaths('alice', $paths['home_dir'])[\pmssTrafficDataPathKey(false, $mode)];
            $payload = unserialize((string) file_get_contents($dataPath));
            $this->assertEquals(30.0, $payload['raw']['month']);
            $this->assertEquals($expectedDaily, $payload['daily']);
            $this->assertEquals($payload['raw']['month'], array_sum($payload['daily']));
        }
    }

    public function testRunCliProcessesWorkerUser(): void
    {
        [$stub, $paths, $processor] = $this->makeTrafficProcessorStubFixture(true);
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
        [$stub, , $processor] = $this->makeTrafficProcessorStubFixture(true);

        list($result, $output) = $this->pmssCaptureStdout(function () use ($processor): int { return $processor->runCli(['/scripts/cron/trafficStats.php', 'ghost'], '/scripts/cron/trafficStats.php'); });

        $this->assertEquals(0, $result);
        $this->assertStringContainsString("Invalid user specified: ghost\n", $output);
        $this->assertEquals([], $stub->saved);
        $this->assertEquals([], $processor->spawnCalls);
    }

    public function testRunCliPrintsNoUsersMessageWithoutDiscoveredUsers(): void
    {
        [, , $processor] = $this->makeTrafficProcessorStubFixture(true);

        list($result, $output) = $this->pmssCaptureStdout(function () use ($processor): int { return $processor->runCli(['/scripts/cron/trafficStats.php'], '/scripts/cron/trafficStats.php'); });

        $this->assertEquals(0, $result);
        $this->assertStringContainsString("No users in this system!\n", $output);
        $this->assertEquals([], $processor->spawnCalls);
    }

    public function testRunCliAggregatesValidAccountsUnderParentLockWithoutSpawning(): void
    {
        foreach (['egress', 'ingress'] as $mode) {
            $paths = $this->makeTrafficPaths('pmss-traffic-cli-', true, ['traffic_mode' => $mode]);
            $script = $mode === 'ingress' ? 'trafficIngressStats.php' : 'trafficStats.php';
            $processor = new class(new \trafficStatistics($paths), $paths) extends \TrafficStatsProcessor {
                protected function runSpawnCommand(string $command): int
                {
                    throw new \RuntimeException('Traffic aggregation must not spawn workers');
                }
            };
            // Valid base/localnet series, plus ghosts missing either or both account prerequisites.
            foreach (['alice', 'user2', 'user10', 'no-passwd', 'no-data'] as $user) {
                $this->createTrafficUser($paths, $user, $user !== 'no-data');
            }
            foreach (['user2', 'user10', 'no-home', 'no-data', 'www-data', 'directory'] as $user) {
                file_put_contents($paths['passwd_file'], "{$user}:x:1001:1001::/nonexistent:/bin/false\n", FILE_APPEND);
            }
            $expectedUsers = ['alice', 'alice-localnet', 'user2', 'user10'];
            foreach (array_merge($expectedUsers, ['ghost', 'ghost-localnet', 'no-home', 'no-passwd', 'www-data']) as $user) {
                file_put_contents($paths['traffic_dir'].'/'.$user, $this->makeTrafficUsageLines([
                    60 => 1048576, 120 => 2097152,
                ])."\n");
            }
            mkdir($paths['traffic_dir'].'/directory');
            mkdir($paths['home_dir'].'/directory');
            $lock = \pmssLockFileAcquire($paths['runtime_dir'].'/aggregation.lock', true);
            $this->assertTrue(is_resource($lock));
            try {
                $this->assertEquals(0, $processor->runCli([$script], $script));
                foreach ($expectedUsers as $user) {
                    $baseUser = \pmssTrafficUserKeyBaseUser($user);
                    $key = \pmssTrafficDataPathKey(\pmssTrafficUserKeyIsLocalnet($user), $mode);
                    $payload = unserialize(file_get_contents(\pmssTrafficDataPaths($baseUser, $paths['home_dir'])[$key]));
                    $this->assertEquals(3.0, $payload['raw']['month']);
                }
                $this->assertTrue(!is_file($paths['home_dir'].'/no-passwd/.trafficData'));
                $this->assertTrue(!is_file($paths['home_dir'].'/no-data/.trafficData'));
                $busy = false;
                $this->assertEquals(false, \pmssLockFileAcquire($paths['runtime_dir'].'/aggregation.lock', true, 'c', false, true, $busy));
                $this->assertTrue($busy);
            } finally {
                fclose($lock);
            }
        }
    }
}
