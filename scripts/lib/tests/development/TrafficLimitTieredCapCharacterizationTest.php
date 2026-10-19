<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/scripts/lib/user/trafficLimit.php';

class TrafficLimitTieredCapCharacterizationTest extends TestCase
{
    public function testNetworkThrottlePolicyKeepsCronDefaultsAndLegacyOverride(): void
    {
        $policy = \pmssTrafficLimitThrottlePolicyFromNetworkConfig([]);

        $this->assertEquals(100, $policy['defaultTrafficCapMbit']);
        $this->assertEquals(true, $policy['progressiveThrottleEnabled']);
        $this->assertEquals(2.5, $policy['progressiveThrottleFloorPercent']);
        $this->assertEquals(0.0, $policy['progressiveThrottleGracePercent']);
        $this->assertEquals(\pmssTrafficLimitDefaultOverageStages(), $policy['overageThrottleStages']);

        $legacyPolicy = \pmssTrafficLimitThrottlePolicyFromNetworkConfig([
            'throttle' => [
                'max' => 0,
                'progressiveThrottleEnabled' => 'off',
                'progressiveThrottleFloorPercent' => 150,
                'progressiveThrottleGracePercent' => '5',
                'overageStages' => \pmssTrafficLimitLegacyOverageStages(),
            ],
        ]);

        $this->assertEquals(100, $legacyPolicy['defaultTrafficCapMbit']);
        $this->assertEquals(false, $legacyPolicy['progressiveThrottleEnabled']);
        $this->assertEquals(100.0, $legacyPolicy['progressiveThrottleFloorPercent']);
        $this->assertEquals(5.0, $legacyPolicy['progressiveThrottleGracePercent']);
        $this->assertEquals(\pmssTrafficLimitDefaultOverageStages(), $legacyPolicy['overageThrottleStages']);
    }

    public function testNetworkThrottlePolicyPreservesCustomSnapshot(): void
    {
        $policy = \pmssTrafficLimitThrottlePolicyFromNetworkConfig([
            'throttle' => [
                'max' => 80,
                'progressiveThrottleEnabled' => 'no',
                'progressiveThrottleFloorPercent' => 5,
                'progressiveThrottleGracePercent' => 7.5,
                'overageStages' => [['overagePercent' => 75.0, 'minOverageGiB' => 2048.0, 'capMbit' => 20]],
            ],
        ]);

        $this->assertEquals([
            'defaultTrafficCapMbit' => 80,
            'progressiveThrottleEnabled' => false,
            'progressiveThrottleFloorPercent' => 5.0,
            'progressiveThrottleGracePercent' => 7.5,
            'overageThrottleStages' => [['overagePercent' => 75.0, 'minOverageGiB' => 2048.0, 'capMbit' => 20]],
        ], $policy);
    }

    public function testTieredCapSelectionCases(): void
    {
        foreach ([
            'higher minimum overage wins' => [[75.0, 6000.0, 100, [
                ['overagePercent' => 75.0, 'minOverageGiB' => 0.0, 'capMbit' => 50],
                ['overagePercent' => 75.0, 'minOverageGiB' => 5120.0, 'capMbit' => 25],
            ]], ['effective' => 25, 'matched' => ['overagePercent' => 75.0, 'minOverageGiB' => 5120.0, 'capMbit' => 25]]],
            'original order breaks perfect ties' => [[50.0, 3072.0, 100, [
                ['overagePercent' => 50.0, 'minOverageGiB' => 3072.0, 'capMbit' => 40],
                ['overagePercent' => 50.0, 'minOverageGiB' => 3072.0, 'capMbit' => 30],
            ]], ['effective' => 40, 'matched' => ['overagePercent' => 50.0, 'minOverageGiB' => 3072.0, 'capMbit' => 40]]],
            'post cap clamps matched stage speed' => [[100.0, 0.0, 8, [
                ['overagePercent' => 100.0, 'minOverageGiB' => 0.0, 'capMbit' => 10],
            ]], ['effective' => 8, 'matched' => ['overagePercent' => 100.0, 'minOverageGiB' => 0.0, 'capMbit' => 10]]],
        ] as $label => $case) {
            $this->assertEquals($case[1], \pmssTrafficLimitSelectTieredCapMbit(...$case[0]), $label);
        }
    }

    public function testThrottlePlanKeepsCronMessages(): void
    {
        $cases = [
            [[100.0, 175.0, 100, [['overagePercent' => 75.0, 'minOverageGiB' => 0.0, 'capMbit' => 50], ['overagePercent' => 75.0, 'minOverageGiB' => 60.0, 'capMbit' => 25]], true, 2.5, 0.0], 25, 'traffic throttle staged (limit=100.00 GiB usage=175.00 GiB overage=75.0% overageGiB=75.00 cap=100 Mbit stageCap=25 Mbit stageOverage=75.0% stageMinOverageGiB=60.00 effective=25 Mbit)'],
            [[100.0, 150.0, 100, [], true, 2.5, 0.0], 50, 'traffic throttle enabled (limit=100.00 GiB usage=150.00 GiB overage=50.0% adjusted=50.0% cap=100 Mbit effective=50 Mbit floor=3 Mbit)'],
            [[100.0, 150.0, 100, [], false, 2.5, 0.0], 100, 'traffic throttle enabled (limit=100.00 GiB usage=150.00 GiB)'],
        ];

        foreach ($cases as $case) {
            $result = \pmssTrafficLimitThrottlePlan(...$case[0]);
            $this->assertEquals($case[1], $result['effectiveCapMbit']);
            $this->assertEquals($case[2], $result['logMessage']);
        }
    }

    public function testPlanCapOutranksDefaultStagesAndProgressive(): void
    {
        $stages = \pmssTrafficLimitDefaultOverageStages();

        // A plan sold as 1 Gbps after the quota keeps 1000 Mbit, under stages and under progressive mode.
        $staged = \pmssTrafficLimitThrottlePlan(100.0, 400.0, 1000, $stages, false, 2.5, 0.0, true);
        $this->assertEquals(1000, $staged['effectiveCapMbit']);
        $this->assertEquals('traffic throttle enabled (limit=100.00 GiB usage=400.00 GiB plan cap=1000 Mbit)', $staged['logMessage']);
        $progressive = \pmssTrafficLimitThrottlePlan(100.0, 400.0, 1000, [], true, 2.5, 0.0, true);
        $this->assertEquals(1000, $progressive['effectiveCapMbit']);

        // A plan figure below the stage cap is kept as well.
        $this->assertEquals(50, \pmssTrafficLimitThrottlePlan(100.0, 400.0, 50, $stages, false, 2.5, 0.0, true)['effectiveCapMbit']);

        // Without a plan cap the default stage still applies to the default cap.
        $default = \pmssTrafficLimitThrottlePlan(100.0, 400.0, 1000, $stages, false, 2.5, 0.0);
        $this->assertEquals(100, $default['effectiveCapMbit']);
    }

    public function testPlanCapReadsOnlyAPositiveConfiguredValue(): void
    {
        $this->assertEquals(1000, \pmssTrafficLimitPlanCapMbit(['trafficCapMbit' => '1000']));
        $this->assertEquals(100, \pmssTrafficLimitPlanCapMbit(['trafficCapMbit' => 100]));
        $this->assertEquals(0, \pmssTrafficLimitPlanCapMbit(['trafficCapMbit' => 0]));
        $this->assertEquals(0, \pmssTrafficLimitPlanCapMbit(['trafficCapMbit' => -5]));
        $this->assertEquals(0, \pmssTrafficLimitPlanCapMbit(['trafficCapMbit' => 'fast']));
        $this->assertEquals(0, \pmssTrafficLimitPlanCapMbit(['ramMiB' => 1024]));
        $this->assertEquals(0, \pmssTrafficLimitPlanCapMbit(null));
    }
}
