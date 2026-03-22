<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TrafficTestCase.php';
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

class TrafficStatsProcessorEdgeTest extends TrafficTestCase
{
    public function testProcessUserIgnoresErroneousLines(): void
    {
        $stub = new StubTrafficStatisticsEdge();
        $paths = $this->makePaths();
        $processor = new \TrafficStatsProcessor($stub, $paths);
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
        return $this->makeTrafficPaths('pmss-traffic-edge-', true);
    }

    private function createUserFixtures(array $paths, string $user): void
    {
        $this->createTrafficUser($paths, $user);
    }

    private function cleanupPaths(array $paths): void
    {
        $this->cleanupTrafficPaths($paths);
    }
}
