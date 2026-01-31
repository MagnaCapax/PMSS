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
}

