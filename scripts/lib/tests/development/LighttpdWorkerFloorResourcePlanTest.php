<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class LighttpdWorkerFloorResourcePlanTest extends TestCase
{
    public function testMissingWorkerFloorKeepsCpuDerivedPlan(): void
    {
        $resources = \pmssLighttpdResourcePlan([], ['cpuQuotaPercent' => 100]);

        $this->assertSame(1, $resources['maxProcs']);
        $this->assertSame(6, $resources['children']);
        $this->assertSame(6, $resources['totalThreads']);
    }

    public function testWorkerFloorRaisesPlanToProcessGroupBoundary(): void
    {
        $resources = \pmssLighttpdResourcePlan(
            [],
            ['cpuQuotaPercent' => 100],
            ['lighttpdMinThreads' => 13]
        );

        $this->assertSame(3, $resources['maxProcs']);
        $this->assertSame(6, $resources['children']);
        $this->assertSame(18, $resources['totalThreads']);
    }

    public function testWorkerFloorCannotExceedThreadCap(): void
    {
        $resources = \pmssLighttpdResourcePlan(
            [],
            ['cpuQuotaPercent' => 100],
            ['lighttpdMinThreads' => 999]
        );

        $this->assertSame(8, $resources['maxProcs']);
        $this->assertSame(48, $resources['totalThreads']);
    }

    public function testWorkerFloorIgnoresInvalidValues(): void
    {
        foreach ([0, -5, '', 'abc'] as $value) {
            $resources = \pmssLighttpdResourcePlan(
                [],
                ['cpuQuotaPercent' => 100],
                ['lighttpdMinThreads' => $value]
            );

            $this->assertSame(1, $resources['maxProcs']);
            $this->assertSame(6, $resources['totalThreads']);
        }
    }

    public function testWorkerFloorCanLoadFromPersistedUserConfig(): void
    {
        $configDir = $this->pmssMakeTempDir('pmss-lighttpd-worker-floor-config-');
        $store = new \UserConfigStore($configDir);
        $this->assertTrue($store->set('alice', [
            'ramMiB' => 512,
            'rtorrentPort' => 5000,
            'quota' => 10,
            'quotaBurst' => 12,
            'lighttpdMinThreads' => '18',
        ]));

        $payload = \pmssLighttpdUserConfigLoad('alice', $store);
        $resources = \pmssLighttpdResourcePlan([], ['cpuQuotaPercent' => 100], $payload);

        $this->assertSame('18', $payload['lighttpdMinThreads']);
        $this->assertSame(3, $resources['maxProcs']);
        $this->assertSame(18, $resources['totalThreads']);
    }
}
