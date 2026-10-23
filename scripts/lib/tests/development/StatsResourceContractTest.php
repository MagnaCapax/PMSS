<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/statsHelpers.php';

/** Characterize the persisted-resource projection and Chart.js output bytes. */
final class StatsResourceContractTest extends TestCase
{
    public function testSerializedStateKeepsMissingInvalidAndValidResults(): void
    {
        $root = $this->pmssMakeTempDir('pmss-stats-state-');
        $path = $root.'/state';
        $missing = array('data' => null, 'time' => null, 'error' => null);
        $this->assertSame($missing, \pmssStatsSerializedStateRead($path, 'invalid'));
        foreach (array('bad', serialize(false), serialize('text'), serialize(array()), serialize(array('raw' => array('month' => 12)))) as $content) {
            file_put_contents($path, $content);
            clearstatcache(true, $path);
            $expected = @unserialize($content, array('allowed_classes' => false));
            $this->assertSame(array('data' => is_array($expected) ? $expected : null,
                'time' => filemtime($path), 'error' => is_array($expected) ? null : 'invalid'),
                \pmssStatsSerializedStateRead($path, 'invalid'));
        }
        symlink($path, $root.'/link');
        $this->assertSame($missing, \pmssStatsSerializedStateRead($root.'/link', 'invalid'));
        $this->assertSame($missing, \pmssStatsSerializedStateRead($root, 'invalid'));
    }

    public function testEmptyResourceModelKeepsItsCompleteShape(): void
    {
        $expected = array(
            'ioReadDisplay' => array(), 'ioWriteDisplay' => array(), 'cpuDisplay' => array(),
            'memoryDisplay' => array(), 'ramHoursDisplay' => array(),
            'memoryCurrent' => 'n/a', 'memoryAnon' => 'n/a', 'memoryFile' => 'n/a',
            'tasksCurrent' => 'n/a', 'ioOperationsMonth' => 0.0, 'ioDailyLabels' => array(),
            'ioDailyRead' => array(), 'ioDailyWrite' => array(), 'ioDailyOperations' => array(),
            'cpuDailyHours' => array(),
        );
        $this->assertSame($expected, \pmssStatsResourceSnapshotBuild(null));
        $this->assertSame($expected, \pmssStatsResourceSnapshotBuild(array()));
    }

    public function testDisplayFallbacksPreserveSavedValuesAndStrictUnitBoundaries(): void
    {
        foreach (array('io_read' => 'ioReadDisplay', 'io_write' => 'ioWriteDisplay', 'memory' => 'memoryDisplay', 'ram_hours' => 'ramHoursDisplay') as $metric => $field) {
            foreach (array(
                array(array(), array(), array()),
                array('bad', 'bad', array()),
                array(array('month' => '', 'week' => false, 'day' => 0, 'hour' => 'saved'), array_fill_keys(array('month', 'week', 'day', 'hour'), 2048), array('month' => '', 'week' => false, 'day' => 0, 'hour' => 'saved')),
                array(array('month' => null), array('month' => 'bad', 'week' => null), array('month' => null)),
                array(array('month' => null), array('month' => '1024', 'week' => 1048576, 'day' => 0, 'hour' => -1024), $metric === 'ram_hours'
                    ? array('month' => '1024GB-hrs', 'week' => '1048576GB-hrs', 'day' => '0GB-hrs', 'hour' => '-1024GB-hrs')
                    : array('month' => '1KiB', 'week' => '1024KiB', 'day' => '0KiB', 'hour' => '-1KiB')),
            ) as $case) {
                $model = \pmssStatsResourceSnapshotBuild(array($metric => array('display' => $case[0], 'raw' => $case[1])));
                $this->assertSame($case[2], $model[$field], $metric);
            }
        }
    }

    public function testCpuAndDailySeriesKeepTheirDistinctConversions(): void
    {
        $model = \pmssStatsResourceSnapshotBuild(array(
            'cpu' => array('display' => array('month' => 'old', 'week' => 'saved'), 'raw' => array('month' => 3600000000000, 'week' => 9, 'day' => 60000000000, 'hour' => 1000000000)),
            'io_read_ops' => array('raw' => array('month' => '3.5')),
            'io_write_ops' => array('raw' => array('month' => 2)),
            'daily' => array('day-b' => array('io_read' => 1048576, 'io_write' => 2097152, 'cpu' => 3600000000000, 'io_read_ops' => 2.125, 'io_write_ops' => 1), 'day-a' => array()),
        ));
        $this->assertSame(array('month' => '1 CPU-hours', 'week' => 'saved', 'day' => '1m', 'hour' => '1s'), $model['cpuDisplay']);
        $this->assertSame(5.5, $model['ioOperationsMonth']);
        $this->assertSame(array('day-b', 'day-a'), $model['ioDailyLabels']);
        foreach (array('ioDailyRead' => array(1.0, 0.0), 'ioDailyWrite' => array(2.0, 0.0), 'ioDailyOperations' => array(3.13, 0.0), 'cpuDailyHours' => array(1.0, 0.0)) as $field => $expected) {
            $this->assertSame($expected, $model[$field], $field);
        }
    }

    public function testChartRenderingKeepsExactJavascriptAndHtmlBytes(): void
    {
        [, $actual] = $this->pmssCaptureStdout(static function (): void {
            \pmssStatsRenderLineChart('chart', array('key' => 'day'), array(
                \pmssStatsChartDataset('Read', array('key' => 2), 'rgba(1, 2, 3, 0.2)', 'rgb(1, 2, 3)'),
            ));
        });
        $expected = <<<'HTML'
<div class="traffic-chart"><canvas id="chart" width="600" height="250"></canvas></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined') return;
    const element = document.getElementById("chart");
    if (!element) return;
    new Chart(element.getContext('2d'), {
        type: 'line',
        data: { labels: ["day"], datasets: [{"label":"Read","data":[2],"fill":true,"backgroundColor":"rgba(1, 2, 3, 0.2)","borderColor":"rgb(1, 2, 3)","tension":0.4,"pointRadius":3}] },
        options: pmssStatsChartOptions()
    });
});
</script>
HTML;
        $this->assertSame($expected."\n", $actual);
    }

    public function testInvalidChartsAndMissingResourcesKeepTheirFailureOutput(): void
    {
        foreach (array(array(), array(null, array('label' => 'incomplete')), array(array('label' => "\xff", 'data' => array(), 'backgroundColor' => '', 'borderColor' => ''))) as $datasets) {
            [, $actual] = $this->pmssCaptureStdout(static function () use ($datasets): void {
                \pmssStatsRenderLineChart('chart', array(), $datasets);
            });
            $this->assertSame('', $actual);
        }
        foreach (array(null => 'Resource data not available.', '<invalid>' => '&lt;invalid&gt;') as $error => $message) {
            [, $actual] = $this->pmssCaptureStdout(static function () use ($error): void {
                \pmssStatsRenderResourceBlocks(array('data' => null, 'error' => $error === '' ? null : $error));
            });
            $this->assertSame('<div class="stats-block resource-summary-block"><h6>Resource snapshot</h6><pre>'.$message.'</pre></div>', $actual);
        }
    }
}
