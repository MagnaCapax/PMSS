<?php
namespace PMSS\Tests;

require_once __DIR__.'/TestCase.php';

/**
 * Shared in-memory traffic statistics behaviour for processor tests.
 */
trait TrafficStatisticsStubTrait
{
    /** @var array<string, string> */
    public $map = [];

    /** @var array<string, mixed> */
    public $saved = [];

    public function getData($user, $timePeriod = 5050)
    {
        return $this->map[$user] ?? '';
    }

    public function saveUserTraffic($user, $data)
    {
        $this->saved[$user] = $data;
    }
}

/**
 * Shared traffic-test fixtures for hermetic processor and statistics suites.
 */
abstract class TrafficTestCase extends TestCase
{
    /** Build an isolated traffic fixture tree for a test. */
    protected function makeTrafficPaths(string $prefix = 'pmss-traffic-', bool $withPasswd = false, array $overrides = []): array
    {
        $root = $this->pmssMakeTempDir($prefix);
        $paths = array_replace([
            'root' => $root,
            'traffic_dir' => $root.'/traffic',
            'home_dir'    => $root.'/home',
            'runtime_dir' => $root.'/run',
        ], $overrides);

        @mkdir($paths['traffic_dir'], 0755, true);
        @mkdir($paths['home_dir'], 0755, true);
        @mkdir($paths['runtime_dir'], 0755, true);
        if ($withPasswd) {
            $paths['passwd_file'] = $root.'/passwd';
            file_put_contents($paths['passwd_file'], "alice:x:1000:1000::{$paths['home_dir']}/alice:/bin/bash\n");
        }

        return $paths;
    }

    /** Create a user home and optional traffic marker file inside a fixture tree. */
    protected function createTrafficUser(array $paths, string $user, bool $withTrafficFile = true): void
    {
        if ($withTrafficFile) {
            file_put_contents($paths['traffic_dir'].'/'.$user, 'seed');
        }
        @mkdir($paths['home_dir'].'/'.$user, 0755, true);
    }

    /** Build an isolated processor fixture with shared path wiring. */
    protected function makeTrafficProcessorFixture(\trafficStatistics $stats, string $processorClass = '\\TrafficStatsProcessor', string $prefix = 'pmss-traffic-', bool $withPasswd = true): array
    {
        $paths = $this->makeTrafficPaths($prefix, $withPasswd);
        return [$paths, new $processorClass($stats, $paths)];
    }

    /** Build a stubbed statistics instance with in-memory input and saved payloads. */
    protected function makeTrafficStatisticsStub(): \trafficStatistics
    {
        return new class extends \trafficStatistics {
            use TrafficStatisticsStubTrait;
        };
    }

    /** Build a processor fixture backed by a fresh in-memory statistics stub. */
    protected function makeTrafficProcessorStubFixture(bool $spy = false, string $prefix = 'pmss-traffic-', bool $withPasswd = true): array
    {
        $stub = $this->makeTrafficStatisticsStub();
        [$paths, $processor] = $spy
            ? $this->makeTrafficProcessorSpyFixture($stub, $prefix, $withPasswd)
            : $this->makeTrafficProcessorFixture($stub, '\\TrafficStatsProcessor', $prefix, $withPasswd);
        return [$stub, $paths, $processor];
    }

    /** Build a processor spy fixture that records worker discovery and spawn requests. */
    protected function makeTrafficProcessorSpyFixture(\trafficStatistics $stats, string $prefix = 'pmss-traffic-', bool $withPasswd = true): array
    {
        $paths = $this->makeTrafficPaths($prefix, $withPasswd);

        return [$paths, new class($stats, $paths) extends \TrafficStatsProcessor {
            public $usersToDiscover = [];
            public $spawnCalls = [];

            public function discoverUsers(): array
            {
                return $this->usersToDiscover;
            }

            public function spawnWorkers(string $scriptPath, array $users): void
            {
                $this->spawnCalls[] = [$scriptPath, $users];
            }
        }];
    }

    /** Create the canonical localnet traffic marker and return its user key. */
    protected function markTrafficUserLocalnet(array $paths, string $user): string
    {
        $localnetUser = $user.'-localnet';
        $this->pmssWriteFile($paths['traffic_dir'].'/'.$localnetUser, 'seed');
        return $localnetUser;
    }

    /** Assert that a traffic payload lands in the expected home and runtime files. */
    protected function assertTrafficPayloadPersistence(array $paths, string $user, array $payload, string $trafficMode = 'egress'): void
    {
        $homePath = pmssTrafficDataPaths(pmssTrafficUserKeyBaseUser($user), $paths['home_dir'])[pmssTrafficDataPathKey(pmssTrafficUserKeyIsLocalnet($user), $trafficMode)];
        $runtimePath = pmssTrafficStatsPath($user, null, $paths['runtime_dir']);
        $this->assertTrue(is_file($homePath));
        $this->assertEquals($payload, unserialize((string) file_get_contents($homePath)));
        if (is_file($runtimePath)) {
            $this->assertEquals($payload, unserialize((string) file_get_contents($runtimePath)));
        }
    }

    /** Save a traffic payload through the shared helper and assert persistence. */
    protected function saveTrafficPayloadAndAssert(array $paths, string $user, array $payload, string $trafficMode = 'egress'): void
    {
        (new \trafficStatistics($paths))->saveUserTraffic($user, $payload);
        $this->assertTrafficPayloadPersistence($paths, $user, $payload, $trafficMode);
    }

    /** Build canonical persisted traffic payload arrays for save/read assertions. */
    protected function makeTrafficPayload(array $raw, array $display = [], array $daily = []): array
    {
        return ['raw' => $raw, 'display' => $display, 'daily' => $daily];
    }

    /** Build traffic log fixtures from seconds-ago => byte-count samples. */
    protected function makeTrafficUsageLines(array $samples, ?int $now = null): string
    {
        $now = $now ?? time();
        $lines = [];
        foreach ($samples as $secondsAgo => $bytes) {
            $lines[] = date('Y-m-d H:i:s', $now - (int) $secondsAgo).': '.(string) $bytes;
        }
        return implode("\n", $lines);
    }
}
