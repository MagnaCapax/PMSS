<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StatsIoOperationsFrontendTest extends TestCase
{
    public function testStatsPageFormatsMonthlyIoOperationsSummary(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/skel/www/stats.php',
            ['function pmssFormatIoOperationsShort($operations)', 'Past 30 days total I/O operations:']
        );
    }

    public function testStatsPageBuildsDailyIoOperationsChartFromTotals(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/skel/www/stats.php',
            ['$ioDailyOperations[] = round($readOps + $writeOps, 2);', "'label' => 'Daily I/O Operations'"]
        );
        $this->pmssAssertRepoFileNotContainsString('etc/skel/www/stats.php', '$iopsDailyAverage');
    }
}
