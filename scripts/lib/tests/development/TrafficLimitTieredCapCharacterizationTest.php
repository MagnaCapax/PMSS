<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/scripts/lib/user/trafficLimit.php';

class TrafficLimitTieredCapCharacterizationTest extends TestCase
{
    public function testHigherMinimumOverageWinsWhenThresholdMatches(): void
    {
        $result = \pmssTrafficLimitSelectTieredCapMbit(75.0, 6000.0, 100, [
            ['overagePercent' => 75.0, 'minOverageGiB' => 0.0, 'capMbit' => 50],
            ['overagePercent' => 75.0, 'minOverageGiB' => 5120.0, 'capMbit' => 25],
        ]);

        $this->assertEquals(
            ['effective' => 25, 'matched' => ['overagePercent' => 75.0, 'minOverageGiB' => 5120.0, 'capMbit' => 25]],
            $result
        );
    }

    public function testOriginalOrderBreaksPerfectTies(): void
    {
        $result = \pmssTrafficLimitSelectTieredCapMbit(50.0, 3072.0, 100, [
            ['overagePercent' => 50.0, 'minOverageGiB' => 3072.0, 'capMbit' => 40],
            ['overagePercent' => 50.0, 'minOverageGiB' => 3072.0, 'capMbit' => 30],
        ]);

        $this->assertEquals(
            ['effective' => 40, 'matched' => ['overagePercent' => 50.0, 'minOverageGiB' => 3072.0, 'capMbit' => 40]],
            $result
        );
    }

    public function testPostCapStillClampsMatchedStageSpeed(): void
    {
        $result = \pmssTrafficLimitSelectTieredCapMbit(100.0, 0.0, 8, [
            ['overagePercent' => 100.0, 'minOverageGiB' => 0.0, 'capMbit' => 10],
        ]);

        $this->assertEquals(8, $result['effective']);
        $this->assertEquals(['overagePercent' => 100.0, 'minOverageGiB' => 0.0, 'capMbit' => 10], $result['matched']);
    }
}
