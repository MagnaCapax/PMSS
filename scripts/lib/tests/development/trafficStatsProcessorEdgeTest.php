<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/traffic/processor.php';

class StubTrafficStatisticsEdge extends \trafficStatistics
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

class TrafficStatsProcessorEdgeTest extends TestCase
{
    public function testProcessUserIgnoresErroneousLines(): void
    {
        $stub = new StubTrafficStatisticsEdge();
        $paths = $this->makePaths();
        $processor = new \TrafficStatsProcessor($stub, $paths);
        $processor->ensureRuntime();
        $this->createUserFixtures($paths, 'alice');

        // malformed lines mixed with valid-looking but enormous values
        $stub->map['alice'] = "bogus line\n".
            date('Y-m-d H:i:s', time() - 60).": 999999999999\n".
            "another bad line";

        try {
            $processor->processUser('alice', $processor->buildCompareTimes());
            $this->assertTrue(isset($stub->saved['alice']), 'Processor should persist zeroed totals');
            $this->assertEquals(0.0, $stub->saved['alice']['raw']['month']);
        } finally {
            $this->cleanupPaths($paths);
        }
    }

    public function testValidateUserFalseWhenMissingPasswd(): void
    {
        $stub = new StubTrafficStatisticsEdge();
        $paths = $this->makePaths();
        @unlink($paths['passwd_file']);
        $processor = new \TrafficStatsProcessor($stub, $paths);
        $this->createUserFixtures($paths, 'ghost');
        $this->assertTrue(!$processor->validateUser('ghost'));
        $this->cleanupPaths($paths);
    }

    private function makePaths(): array
    {
        $root = sys_get_temp_dir().'/pmss-traffic-edge-'.bin2hex(random_bytes(4));
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

    private function cleanupPaths(array $paths): void
    {
        $root = dirname($paths['traffic_dir']);
        if (!file_exists($root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($root);
    }
}
