<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class CustomerStatsLayoutTest extends TestCase
{
    public function testResourceBasicsRenderAsCompactTopSnapshot(): void
    {
        $stats = $this->pmssReadRepoFile('etc/skel/www/stats.php');

        $this->assertOrderedStrings(
            array('<h6>Resource snapshot</h6>', '<h6>Storage I/O</h6>', '<h6>Memory pressure</h6>'),
            $stats,
            'Missing stats layout marker: ',
            'Resource summary order changed at: '
        );
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/stats.php', array(
            'class="stats-block resource-summary-block"',
            'class="resource-summary-strip"',
            'class="resource-summary-label">CPU</span>',
            'class="resource-summary-label">Memory</span>',
            'class="resource-summary-label">Processes</span>',
        ));
        $this->pmssAssertRepoFileNotContainsStrings('etc/skel/www/stats.php', array(
            '<h6>CPU usage</h6>',
            '<h6>Memory usage</h6>',
            '<h6>Process count</h6>',
        ));
    }

    public function testBaseResourcesCgroupOutputIsNotHeightClipped(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/stats.php', array(
            'class="stats-block stats-block-base-resources"',
            'class="stats-base-resources-pre"',
            '.stats-block-base-resources .stats-base-resources-pre',
            'max-height: none;',
            'overflow-y: visible;',
        ));
    }
}
