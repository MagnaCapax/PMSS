<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/traffic.php';

class TrafficStatisticsIngressTest extends TestCase
{
    private function makePaths(): array
    {
        $root = sys_get_temp_dir().'/pmss-traffic-'.bin2hex(random_bytes(4));
        $paths = [
            'traffic_dir' => $root.'/traffic',
            'home_dir'    => $root.'/home',
            'runtime_dir' => $root.'/run',
        ];
        @mkdir($paths['traffic_dir'], 0755, true);
        @mkdir($paths['home_dir'], 0755, true);
        @mkdir($paths['runtime_dir'], 0755, true);
        return $paths;
    }

    private function makeUser(array $paths, string $user): void
    {
        @mkdir($paths['home_dir'].'/'.$user, 0755, true);
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
