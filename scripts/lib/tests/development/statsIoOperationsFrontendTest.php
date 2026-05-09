<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StatsIoOperationsFrontendTest extends TestCase
{
    public function testStatsPageFormatsMonthlyIoOperationsSummary(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/stats.php');

        $this->assertStringContainsString('function pmssFormatIoOperationsShort($operations)', $source);
        $this->assertStringContainsString('Past 30 days total I/O operations:', $source);
    }

    public function testStatsPageBuildsDailyIoOperationsChartFromTotals(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/stats.php');

        $this->assertStringContainsString('$ioDailyOperations[] = round($readOps + $writeOps, 2);', $source);
        $this->assertStringContainsString("label: 'Daily I/O Operations'", $source);
        $this->assertStringNotContainsString('$iopsDailyAverage', $source);
    }
}
