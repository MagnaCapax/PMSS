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
}
