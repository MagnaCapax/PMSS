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

    public function testSaveUserTrafficPersistsAcrossSupportedModes(): void
    {
        foreach ([
            ['paths' => $this->makeTrafficPaths(), 'user' => 'alice', 'mode' => 'egress'],
            ['paths' => $this->makeTrafficPaths('pmss-traffic-', false, ['traffic_mode' => 'ingress']), 'user' => 'alice', 'mode' => 'ingress'],
            ['paths' => $this->makeTrafficPaths('pmss-traffic-', false, ['traffic_mode' => 'ingress']), 'user' => 'alice-localnet', 'mode' => 'ingress'],
        ] as $case) {
            $paths = $case['paths'];
            $this->createTrafficUser($paths, 'alice', false);
            $user = $case['user'] === 'alice-localnet' ? $this->markTrafficUserLocalnet($paths, 'alice') : $case['user'];
            $this->saveTrafficPayloadAndAssert($paths, $user, $this->makeTrafficPayload(['month' => 1]), $case['mode']);
        }
    }

    public function testSaveUserTrafficInvalidModeFallsBackToEgress(): void
    {
        $paths = $this->makeTrafficPaths('pmss-traffic-', false, ['traffic_mode' => 'bogus']);
        $this->createTrafficUser($paths, 'alice', false);
        $payload = $this->makeTrafficPayload(['month' => 1]);
        $this->saveTrafficPayloadAndAssert($paths, 'alice', $payload);
        $this->assertTrue(!is_file($paths['home_dir'].'/alice/.trafficDataIngress'));
    }
}
