<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/statsHelpers.php';

class StatsIoOperationsFrontendTest extends TestCase
{
    public function testResourceSnapshotLocksIoOperationAndDailySeriesModel(): void
    {
        $snapshot = \pmssStatsResourceSnapshotBuild(array(
            'io_read_ops' => array('raw' => array('month' => 1200.0)),
            'io_write_ops' => array('raw' => array('month' => 45.0)),
            'daily' => array(
                '2026-06-01' => array('io_read' => 1048576, 'io_write' => 2097152, 'io_read_ops' => 7, 'io_write_ops' => 3, 'cpu' => 3600000000000),
                '2026-06-02' => array('io_read' => 3145728, 'io_write_ops' => 4),
            ),
        ));

        $this->pmssAssertArraySubsetSame(
            array(
                'ioOperationsMonth' => 1245.0,
                'ioDailyLabels' => array('2026-06-01', '2026-06-02'),
                'ioDailyRead' => array(1.0, 3.0),
                'ioDailyWrite' => array(2.0, 0.0),
                'ioDailyOperations' => array(10.0, 4.0),
                'cpuDailyHours' => array(1.0, 0.0),
            ),
            $snapshot
        );

        $this->assertSame('1.25 thousand IO operations', \pmssFormatIoOperationsShort($snapshot['ioOperationsMonth']));
    }
}
