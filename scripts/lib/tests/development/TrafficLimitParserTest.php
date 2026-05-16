<?php
namespace PMSS\Tests;

require_once __DIR__.'/../../user/trafficLimit.php';

class TrafficLimitParserTest extends TestCase
{
    public function testParsesValidGiBValues(): void
    {
        foreach ([[500, 500], ['123', 123], ['42GiB', 42], ['  7  gib ', 7]] as $case) {
            $err = null;

            $this->assertEquals($case[1], pmssTrafficLimitParseGiB($case[0], $err));
            $this->assertEquals(null, $err);
        }
    }

    public function testRejectsInvalidGiBValues(): void
    {
        $cases = [
            ['   ', 'empty'],
            ['-1', 'invalid format'],
            ['1.5', 'invalid format'],
            [true, 'missing value'],
            [null, 'missing'],
            [1.1, 'must be an integer'],
        ];

        foreach ($cases as $case) {
            $err = null;

            $this->assertEquals(null, pmssTrafficLimitParseGiB($case[0], $err));
            $this->assertEquals($case[1], $err);
        }
    }

    public function testProgressiveCapCases(): void
    {
        $cases = [
            [100, 0.0, 2.5, 0.0, 1, 100, 0.0, null],
            [100, 5.0, 2.5, 0.0, 1, 95, null, null],
            [100, 100.0, 2.5, 0.0, 1, 3, null, 3],
            [100, 5.0, 2.5, 5.0, 1, 100, 0.0, null],
            [80, 10.0, 150.0, 0.0, 1, 80, null, 80],
            [0, 50.0, 2.5, 0.0, 1, 0, 0.0, 0],
        ];

        foreach ($cases as $case) {
            $result = pmssTrafficLimitComputeProgressiveCapMbit($case[0], $case[1], $case[2], $case[3], $case[4]);

            $this->assertEquals($case[5], $result['effective']);
            if ($case[6] !== null) $this->assertEquals($case[6], $result['adjustedOverage']);
            if ($case[7] !== null) $this->assertEquals($case[7], $result['floorMbit']);
        }
    }

    public function testDefaultTieredCapCases(): void
    {
        foreach ([[206.0, 515.0, 100, 100], [1.0, 1.0, 100, 100], [400.0, 10000.0, 100, 100], [206.0, 515.0, 50, 50]] as $case) {
            $result = pmssTrafficLimitSelectTieredCapMbit($case[0], $case[1], $case[2], pmssTrafficLimitDefaultOverageStages());

            $this->assertEquals($case[3], $result['effective']);
            $this->assertEquals(0.0, $result['matched']['overagePercent']);
        }
    }

    public function testLegacyDefaultOverageStagesAreDetected(): void
    {
        $this->assertTrue(pmssTrafficLimitOverageStagesMatchLegacyDefault([
            ['overagePercent' => 200, 'capMbit' => 1],
            ['overagePercent' => 125, 'capMbit' => 1],
            ['overagePercent' => 100, 'capMbit' => 10],
            ['overagePercent' => 75, 'minOverageGiB' => 5120, 'capMbit' => 25],
            ['overagePercent' => 50, 'minOverageGiB' => 3072, 'capMbit' => 50],
        ]));
    }

    public function testCustomOverageStagesAreNotTreatedAsLegacyDefault(): void
    {
        $this->assertFalse(pmssTrafficLimitOverageStagesMatchLegacyDefault([
            ['overagePercent' => 0, 'capMbit' => 100],
        ]));
    }

    public function testTieredCapIgnoresInvalidStages(): void
    {
        $result = pmssTrafficLimitSelectTieredCapMbit(
            60.0,
            5000.0,
            100,
            [
                ['overagePercent' => 'wat', 'capMbit' => 10],
                ['overagePercent' => 50],
                ['capMbit' => 10],
            ]
        );
        $this->assertEquals(100, $result['effective']);
        $this->assertEquals(null, $result['matched']);
    }
}
