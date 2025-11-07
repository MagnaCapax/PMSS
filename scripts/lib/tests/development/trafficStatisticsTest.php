<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/traffic.php';

class TrafficStatisticsTest extends TestCase
{
    public function testParseLineValid(): void
    {
        $ts = new \trafficStatistics();
        $line = date('Y-m-d H:i:s').': 1048576';
        $parsed = $ts->parseLine($line);
        $this->assertTrue($parsed !== false);
        $this->assertEquals(1.0, $parsed['data']);
    }

    public function testParseLineRejectsGiganticTransfer(): void
    {
        $ts = new \trafficStatistics();
        $line = date('Y-m-d H:i:s').': '.(150000 * 1024 * 1024 + 1);
        $parsed = $ts->parseLine($line);
        $this->assertTrue($parsed === false);
    }

    public function testParseLineRejectsMalformed(): void
    {
        $ts = new \trafficStatistics();
        $this->assertTrue($ts->parseLine('bad data') === false);
    }
}
