<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StatsIoOperationsFrontendTest extends TestCase
{
    public function testStatsPageFormatsMonthlyIoOperationsSummary(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/stats.php');

        $this->assertStringContainsAllStrings([
            'function pmssFormatIoOperationsShort($operations)',
            'Past 30 days total I/O operations:',
        ], $source);
    }

    public function testStatsPageBuildsDailyIoOperationsChartFromTotals(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/stats.php');

        $this->assertStringContainsAllStrings([
            '$ioDailyOperations[] = round($readOps + $writeOps, 2);',
            "label: 'Daily I/O Operations'",
        ], $source);
        $this->assertStringNotContainsString('$iopsDailyAverage', $source);
    }
}
