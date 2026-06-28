<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/trafficThrottlePolicy.php';

class TrafficThrottlePolicyNormalizationTest extends TestCase
{
    public function testNormalizeStagesDropsInvalidRowsAndOrdersForEnforcement(): void
    {
        $this->assertEquals([
            ['overagePercent' => 75.0, 'minOverageGiB' => 5120.0, 'capMbit' => 10, 'index' => 2],
            ['overagePercent' => 75.0, 'minOverageGiB' => 100.0, 'capMbit' => 25, 'index' => 1],
            ['overagePercent' => 50.0, 'minOverageGiB' => 0.0, 'capMbit' => 40, 'index' => 0],
            ['overagePercent' => 50.0, 'minOverageGiB' => 0.0, 'capMbit' => 30, 'index' => 5],
        ], \pmssTrafficLimitNormalizeOverageStages([
            ['overagePercent' => 50.0, 'minOverageGiB' => 0.0, 'capMbit' => 40],
            ['overagePercent' => 75.0, 'minOverageGiB' => 100.0, 'capMbit' => 25],
            ['overagePercent' => 75.0, 'minOverageGiB' => 5120.0, 'capMbit' => 10],
            ['overagePercent' => 100.0, 'minOverageGiB' => 0.0, 'capMbit' => 0],
            ['overagePercent' => 'bogus', 'minOverageGiB' => 0.0, 'capMbit' => 100],
            ['overagePercent' => 50.0, 'capMbit' => 30],
        ]));
    }

    public function testTierSelectionAcceptsUnsortedStagesWithoutCallerSorting(): void
    {
        $result = \pmssTrafficLimitSelectTieredCapMbit(75.0, 6000.0, 100, [
            ['overagePercent' => 50.0, 'minOverageGiB' => 3072.0, 'capMbit' => 50],
            ['overagePercent' => 75.0, 'minOverageGiB' => 0.0, 'capMbit' => 40],
            ['overagePercent' => 75.0, 'minOverageGiB' => 5120.0, 'capMbit' => 25],
        ]);

        $this->assertEquals([
            'effective' => 25,
            'matched' => ['overagePercent' => 75.0, 'minOverageGiB' => 5120.0, 'capMbit' => 25],
        ], $result);
    }

    public function testThrottlePlanCronMessageSnapshots(): void
    {
        $cases = [
            'tiered' => [
                [100.0, 175.0, 100, [
                    ['overagePercent' => 75.0, 'minOverageGiB' => 0.0, 'capMbit' => 50],
                    ['overagePercent' => 75.0, 'minOverageGiB' => 60.0, 'capMbit' => 25],
                ], true, 2.5, 0.0],
                25,
                'traffic throttle staged (limit=100.00 GiB usage=175.00 GiB overage=75.0% overageGiB=75.00 cap=100 Mbit stageCap=25 Mbit stageOverage=75.0% stageMinOverageGiB=60.00 effective=25 Mbit)',
            ],
            'progressive' => [
                [100.0, 150.0, 100, [], true, 2.5, 0.0],
                50,
                'traffic throttle enabled (limit=100.00 GiB usage=150.00 GiB overage=50.0% adjusted=50.0% cap=100 Mbit effective=50 Mbit floor=3 Mbit)',
            ],
            'disabled' => [
                [100.0, 150.0, 100, [], false, 2.5, 0.0],
                100,
                'traffic throttle enabled (limit=100.00 GiB usage=150.00 GiB)',
            ],
        ];

        foreach ($cases as $label => $case) {
            $result = \pmssTrafficLimitThrottlePlan(...$case[0]);
            $this->assertEquals($case[1], $result['effectiveCapMbit'], $label);
            $this->assertEquals($case[2], $result['logMessage'], $label);
        }
    }
}
