<?php
namespace PMSS\Tests;

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

class TrafficStatsProcessorTest extends TestCase
{
    public function testSanitizeUser(): void
    {
        $processor = $this->makeProcessor();
        $this->assertEquals('alice-bob', $processor->sanitizeUser('alice!@#-bob'));
    }

    public function testFormatDataDisplay(): void
    {
        $processor = $this->makeProcessor();
        $formatted = $processor->formatDataDisplay([
            '15min' => 100,
            'hour'  => 2048,
            'day'   => 2048 * 2048,
        ]);
        $this->assertEquals('100MiB', $formatted['15min']);
        $this->assertTrue(strpos($formatted['hour'], 'GiB') !== false);
        $this->assertTrue(strpos($formatted['day'], 'TiB') !== false);
    }

    public function testProcessUserPersistsData(): void
    {
        $stub = new StubTrafficStatistics();
        $paths = $this->makePaths();
        $processor = new \TrafficStatsProcessor($stub, $paths);
        $processor->ensureRuntime();

        $user = 'alice';
        $this->createUserFixtures($paths, $user);

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

    private function makeProcessor(): \TrafficStatsProcessor
    {
        return new \TrafficStatsProcessor(new StubTrafficStatistics(), $this->makePaths());
    }

    private function makePaths(): array
    {
        $root = sys_get_temp_dir().'/pmss-traffic-'.bin2hex(random_bytes(4));
        $paths = [
            'traffic_dir' => $root.'/traffic',
            'home_dir'    => $root.'/home',
            'runtime_dir' => $root.'/run',
            'passwd_file' => $root.'/passwd',
        ];
        @mkdir($paths['traffic_dir'], 0755, true);
        @mkdir($paths['home_dir'], 0755, true);
        @mkdir($paths['runtime_dir'], 0755, true);
        file_put_contents($paths['passwd_file'], "alice:x:1000:1000::{$paths['home_dir']}/alice:/bin/bash\n");
        return $paths;
    }

    private function createUserFixtures(array $paths, string $user): void
    {
        file_put_contents($paths['traffic_dir'].'/'.$user, 'seed');
        @mkdir($paths['home_dir'].'/'.$user, 0755, true);
    }
}
