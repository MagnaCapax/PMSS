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
