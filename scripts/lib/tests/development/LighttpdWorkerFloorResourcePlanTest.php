<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class LighttpdWorkerFloorResourcePlanTest extends TestCase
{
    public function testMissingWorkerFloorKeepsCpuDerivedPlan(): void
    {
        $resources = \pmssLighttpdResourcePlan([], ['cpuQuotaPercent' => 100]);

        $this->pmssAssertArraySubsetSame(['maxProcs' => 1, 'children' => 6, 'totalThreads' => 6], $resources);
    }

    public function testSystemdShowQuotaSetsPhpPoolSize(): void
    {
        $props = \pmssParseSystemdPropertyOutput(
            ['CPUQuotaPerSecUSec', 'CPUQuotaPeriodUSec'],
            "CPUQuotaPerSecUSec=2s\nCPUQuotaPeriodUSec=infinity\n"
        );
        $resources = \pmssLighttpdResourcePlan($props, ['cpuQuotaPercent' => 85]);

        $this->pmssAssertArraySubsetSame([
            'cpuQuotaPercent' => 200, 'maxProcs' => 2, 'children' => 6, 'totalThreads' => 12,
        ], $resources);
    }

    public function testWorkerFloorRaisesPlanToProcessGroupBoundary(): void
    {
        $resources = \pmssLighttpdResourcePlan(
            [],
            ['cpuQuotaPercent' => 100],
            ['lighttpdMinThreads' => 13]
        );

        $this->pmssAssertArraySubsetSame(['maxProcs' => 3, 'children' => 6, 'totalThreads' => 18], $resources);
    }

    public function testWorkerFloorCannotExceedThreadCap(): void
    {
        $resources = \pmssLighttpdResourcePlan(
            [],
            ['cpuQuotaPercent' => 100],
            ['lighttpdMinThreads' => 999]
        );

        $this->pmssAssertArraySubsetSame(['maxProcs' => 8, 'totalThreads' => 48], $resources);
    }

    public function testWorkerFloorIgnoresInvalidValues(): void
    {
        foreach ([0, -5, '', 'abc'] as $value) {
            $resources = \pmssLighttpdResourcePlan(
                [],
                ['cpuQuotaPercent' => 100],
                ['lighttpdMinThreads' => $value]
            );

            $this->pmssAssertArraySubsetSame(['maxProcs' => 1, 'totalThreads' => 6], $resources);
        }
    }

    public function testWorkerFloorCanLoadFromPersistedUserConfig(): void
    {
        $configDir = $this->pmssMakeTempDir('pmss-lighttpd-worker-floor-config-');
        $store = new \UserConfigStore($configDir);
        $this->assertTrue($store->set('fixtur01', [
            'ramMiB' => 512,
            'rtorrentPort' => 5000,
            'quota' => 10,
            'quotaBurst' => 12,
            'lighttpdMinThreads' => '18',
        ]));

        $payload = \pmssLighttpdUserConfigLoad('fixtur01', $store);
        $resources = \pmssLighttpdResourcePlan([], ['cpuQuotaPercent' => 100], $payload);

        $this->assertSame('18', $payload['lighttpdMinThreads']);
        $this->pmssAssertArraySubsetSame(['maxProcs' => 3, 'totalThreads' => 18], $resources);
    }
}
