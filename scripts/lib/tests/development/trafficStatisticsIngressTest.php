<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TrafficTestCase.php';
require_once dirname(__DIR__, 2).'/traffic.php';

class TrafficStatisticsIngressTest extends TrafficTestCase
{
    public function testGetDataUsesCustomTrafficDir(): void
    {
        $paths = $this->makeTrafficPaths();
        $user = 'alice';
        @file_put_contents($paths['traffic_dir'].'/'.$user, $this->makeTrafficUsageLines([0 => 1048576])."\n");
        $stats = new \trafficStatistics($paths);
        $data = $stats->getData($user, 1);
        $this->assertStringContainsString('1048576', $data);
    }

    public function testSaveUserTrafficWritesDefaultFile(): void
    {
        $paths = $this->makeTrafficPaths();
        $this->createTrafficUser($paths, 'alice', false);
        $stats = new \trafficStatistics($paths);
        $payload = $this->makeTrafficPayload(['month' => 1]);
        $stats->saveUserTraffic('alice', $payload);
        $this->assertTrafficPayloadPersistence($paths, 'alice', $payload);
    }

    public function testSaveUserTrafficWritesIngressFile(): void
    {
        $paths = $this->makeTrafficPaths('pmss-traffic-', false, ['traffic_mode' => 'ingress']);
        $this->createTrafficUser($paths, 'alice', false);
        $stats = new \trafficStatistics($paths);
        $payload = $this->makeTrafficPayload(['month' => 1]);
        $stats->saveUserTraffic('alice', $payload);
        $this->assertTrafficPayloadPersistence($paths, 'alice', $payload, 'ingress');
    }

    public function testSaveUserTrafficIngressLocalnetSuffixWritesLocalFile(): void
    {
        $paths = $this->makeTrafficPaths('pmss-traffic-', false, ['traffic_mode' => 'ingress']);
        $this->createTrafficUser($paths, 'alice', false);
        $user = $this->markTrafficUserLocalnet($paths, 'alice');
        $stats = new \trafficStatistics($paths);
        $payload = $this->makeTrafficPayload(['month' => 1]);
        $stats->saveUserTraffic($user, $payload);
        $this->assertTrafficPayloadPersistence($paths, $user, $payload, 'ingress');
    }

    public function testSaveUserTrafficInvalidModeFallsBackToEgress(): void
    {
        $paths = $this->makeTrafficPaths('pmss-traffic-', false, ['traffic_mode' => 'bogus']);
        $this->createTrafficUser($paths, 'alice', false);
        $stats = new \trafficStatistics($paths);
        $payload = $this->makeTrafficPayload(['month' => 1]);
        $stats->saveUserTraffic('alice', $payload);
        $this->assertTrafficPayloadPersistence($paths, 'alice', $payload);
        $this->assertTrue(!is_file($paths['home_dir'].'/alice/.trafficDataIngress'));
    }
}
