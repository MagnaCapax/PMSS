<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources.php';

class ResourceStatisticsTest extends TestCase
{
    public function testParseLineValid(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1024 2048 3000 4096 7';
        $parsed = $stats->parseLine($line);
        $this->assertTrue($parsed !== false);
        $this->assertEquals(1024.0, $parsed['io_read']);
        $this->assertEquals(7.0, $parsed['tasks']);
    }

    public function testParseLineRejectsMalformed(): void
    {
        $stats = new \resourceStatistics();
        $this->assertTrue($stats->parseLine('bad data') === false);
    }

    public function testParseLineRejectsNonNumeric(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1 2 nope 4 5';
        $this->assertTrue($stats->parseLine($line) === false);
    }

    public function testParseLineRejectsMissingTokens(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1 2 3';
        $this->assertTrue($stats->parseLine($line) === false);
    }
}
