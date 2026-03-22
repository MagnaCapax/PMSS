<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TrafficTestCase.php';
require_once dirname(__DIR__, 2).'/traffic.php';

class TrafficStatisticsIngressTest extends TrafficTestCase
{
    private function makePaths(): array
    {
        return $this->makeTrafficPaths();
    }

    private function makeUser(array $paths, string $user): void
    {
        $this->createTrafficUser($paths, $user, false);
    }

    public function testGetDataUsesCustomTrafficDir(): void
    {
        $paths = $this->makePaths();
        $user = 'alice';
        @file_put_contents($paths['traffic_dir'].'/'.$user, date('Y-m-d H:i:s').": 1048576\n");
        $stats = new \trafficStatistics($paths);
        $data = $stats->getData($user, 1);
        $this->assertStringContainsString('1048576', $data);
    }

    public function testSaveUserTrafficWritesDefaultFile(): void
    {
        $paths = $this->makePaths();
        $this->makeUser($paths, 'alice');
        $stats = new \trafficStatistics($paths);
        $stats->saveUserTraffic('alice', ['raw' => ['month' => 1], 'display' => [], 'daily' => []]);
        $this->assertTrue(is_file($paths['home_dir'].'/alice/.trafficData'));
    }

    public function testSaveUserTrafficWritesIngressFile(): void
    {
        $paths = $this->makePaths();
        $this->makeUser($paths, 'alice');
        $paths['traffic_mode'] = 'ingress';
        $stats = new \trafficStatistics($paths);
        $stats->saveUserTraffic('alice', ['raw' => ['month' => 1], 'display' => [], 'daily' => []]);
        $this->assertTrue(is_file($paths['home_dir'].'/alice/.trafficDataIngress'));
    }

    public function testSaveUserTrafficIngressLocalnetSuffixWritesLocalFile(): void
    {
        $paths = $this->makePaths();
        $this->makeUser($paths, 'alice');
        $paths['traffic_mode'] = 'ingress';
        $stats = new \trafficStatistics($paths);
        $stats->saveUserTraffic('alice-localnet', ['raw' => ['month' => 1], 'display' => [], 'daily' => []]);
        $this->assertTrue(is_file($paths['home_dir'].'/alice/.trafficDataIngressLocal'));
    }

    public function testSaveUserTrafficInvalidModeFallsBackToEgress(): void
    {
        $paths = $this->makePaths();
        $this->makeUser($paths, 'alice');
        $paths['traffic_mode'] = 'bogus';
        $stats = new \trafficStatistics($paths);
        $stats->saveUserTraffic('alice', ['raw' => ['month' => 1], 'display' => [], 'daily' => []]);
        $this->assertTrue(is_file($paths['home_dir'].'/alice/.trafficData'));
        $this->assertTrue(!is_file($paths['home_dir'].'/alice/.trafficDataIngress'));
    }
}
