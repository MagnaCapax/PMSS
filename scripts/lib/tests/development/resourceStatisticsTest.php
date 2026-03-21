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
        $this->assertEquals(0.0, $parsed['io_read_ops']);
        $this->assertEquals(7.0, $parsed['tasks']);
    }

    public function testParseLineWithOpsFields(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1024 2048 12 34 3000 4096 7';
        $parsed = $stats->parseLine($line);
        $this->assertTrue($parsed !== false);
        $this->assertEquals(12.0, $parsed['io_read_ops']);
        $this->assertEquals(34.0, $parsed['io_write_ops']);
        $this->assertEquals(3000.0, $parsed['cpu']);
    }

    public function testParseLineIgnoresLegacyTrailingTokenOutsideOpsShape(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1024 2048 3000 4096 7 ignored';
        $parsed = $stats->parseLine($line);
        $this->assertTrue($parsed !== false);
        $this->assertEquals(0.0, $parsed['io_read_ops']);
        $this->assertEquals(3000.0, $parsed['cpu']);
        $this->assertEquals(7.0, $parsed['tasks']);
    }

    public function testParseLineWithMemoryBreakdownFields(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1024 2048 12 34 3000 4096 7 512 1024';
        $parsed = $stats->parseLine($line);
        $this->assertTrue($parsed !== false);
        $this->assertEquals(512.0, $parsed['memory_anon']);
        $this->assertEquals(1024.0, $parsed['memory_file']);
    }

    public function testParseLineRejectsPartialMemoryBreakdownFields(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1024 2048 12 34 3000 4096 7 512';
        $this->assertTrue($stats->parseLine($line) === false);
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
