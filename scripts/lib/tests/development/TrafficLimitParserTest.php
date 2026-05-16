<?php
namespace PMSS\Tests;

require_once __DIR__.'/../../user/trafficLimit.php';

class TrafficLimitParserTest extends TestCase
{
    public function testParsesIntegerGiB(): void
    {
        $err = null;
        $this->assertEquals(500, pmssTrafficLimitParseGiB(500, $err));
        $this->assertEquals(null, $err);
    }

    public function testParsesNumericStringGiB(): void
    {
        $err = null;
        $this->assertEquals(123, pmssTrafficLimitParseGiB('123', $err));
        $this->assertEquals(null, $err);
    }

    public function testParsesGiBSuffix(): void
    {
        $err = null;
        $this->assertEquals(42, pmssTrafficLimitParseGiB('42GiB', $err));
        $this->assertEquals(null, $err);
    }

    public function testParsesGiBSuffixCaseInsensitiveAndSpaced(): void
    {
        $err = null;
        $this->assertEquals(7, pmssTrafficLimitParseGiB('  7  gib ', $err));
        $this->assertEquals(null, $err);
    }

    public function testRejectsEmptyString(): void
    {
        $err = null;
        $this->assertEquals(null, pmssTrafficLimitParseGiB('   ', $err));
        $this->assertEquals('empty', $err);
    }

    public function testRejectsNegativeString(): void
    {
        $err = null;
        $this->assertEquals(null, pmssTrafficLimitParseGiB('-1', $err));
        $this->assertEquals('invalid format', $err);
    }

    public function testRejectsFractionalString(): void
    {
        $err = null;
        $this->assertEquals(null, pmssTrafficLimitParseGiB('1.5', $err));
        $this->assertEquals('invalid format', $err);
    }

    public function testRejectsTrueFlagValue(): void
    {
        $err = null;
        $this->assertEquals(null, pmssTrafficLimitParseGiB(true, $err));
        $this->assertEquals('missing value', $err);
    }

    public function testRejectsNullValue(): void
    {
        $err = null;
        $this->assertEquals(null, pmssTrafficLimitParseGiB(null, $err));
        $this->assertEquals('missing', $err);
    }

    public function testRejectsFractionalFloat(): void
    {
        $err = null;
        $this->assertEquals(null, pmssTrafficLimitParseGiB(1.1, $err));
        $this->assertEquals('must be an integer', $err);
    }

    public function testProgressiveCapReturnsBaseWhenNoOverage(): void
    {
        $result = pmssTrafficLimitComputeProgressiveCapMbit(100, 0.0, 2.5, 0.0, 1);
        $this->assertEquals(100, $result['effective']);
        $this->assertEquals(0.0, $result['adjustedOverage']);
    }

    public function testProgressiveCapAppliesOverage(): void
    {
        $result = pmssTrafficLimitComputeProgressiveCapMbit(100, 5.0, 2.5, 0.0, 1);
        $this->assertEquals(95, $result['effective']);
    }

    public function testProgressiveCapHonorsFloorPercent(): void
    {
        $result = pmssTrafficLimitComputeProgressiveCapMbit(100, 100.0, 2.5, 0.0, 1);
        $this->assertEquals(3, $result['effective']);
        $this->assertEquals(3, $result['floorMbit']);
    }

    public function testProgressiveCapAppliesGracePercent(): void
    {
        $result = pmssTrafficLimitComputeProgressiveCapMbit(100, 5.0, 2.5, 5.0, 1);
        $this->assertEquals(100, $result['effective']);
        $this->assertEquals(0.0, $result['adjustedOverage']);
    }

    public function testProgressiveCapClampsFloorPercentToBase(): void
    {
        $result = pmssTrafficLimitComputeProgressiveCapMbit(80, 10.0, 150.0, 0.0, 1);
        $this->assertEquals(80, $result['floorMbit']);
        $this->assertEquals(80, $result['effective']);
    }

    public function testProgressiveCapReturnsZeroForZeroBase(): void
    {
        $result = pmssTrafficLimitComputeProgressiveCapMbit(0, 50.0, 2.5, 0.0, 1);
        $this->assertEquals(0, $result['effective']);
        $this->assertEquals(0.0, $result['adjustedOverage']);
    }

    public function testDefaultTieredCapHoldsPostCapAtOneHundredMbit(): void
    {
        $result = pmssTrafficLimitSelectTieredCapMbit(
            206.0,
            515.0,
            100,
            pmssTrafficLimitDefaultOverageStages()
        );
        $this->assertEquals(100, $result['effective']);
        $this->assertEquals(0.0, $result['matched']['overagePercent']);
    }

    public function testDefaultTieredCapAppliesAtSmallPostCapOverage(): void
    {
        $result = pmssTrafficLimitSelectTieredCapMbit(
            1.0,
            1.0,
            100,
            pmssTrafficLimitDefaultOverageStages()
        );
        $this->assertEquals(100, $result['effective']);
        $this->assertEquals(0.0, $result['matched']['overagePercent']);
    }

    public function testDefaultTieredCapAppliesAtHeavyPostCapOverage(): void
    {
        $result = pmssTrafficLimitSelectTieredCapMbit(
            400.0,
            10000.0,
            100,
            pmssTrafficLimitDefaultOverageStages()
        );
        $this->assertEquals(100, $result['effective']);
        $this->assertEquals(0.0, $result['matched']['overagePercent']);
    }

    public function testDefaultTieredCapNeverIncreasesLowerBaseCap(): void
    {
        $result = pmssTrafficLimitSelectTieredCapMbit(
            206.0,
            515.0,
            50,
            pmssTrafficLimitDefaultOverageStages()
        );
        $this->assertEquals(50, $result['effective']);
        $this->assertEquals(0.0, $result['matched']['overagePercent']);
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
