<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/storageHealth.php';

class StorageHealthPerformanceStatusTest extends TestCase
{
    public function testPerformanceStatusVariants(): void
    {
        foreach ([
            [[['array' => 'md0', 'severity' => 'warn', 'flags' => ['rebuild_in_progress']]], ['status' => 'performance_limited', 'reason' => 'RAID md0 resync in progress', 'array' => 'md0']],
            [[['array' => 'md1', 'severity' => 'fail', 'flags' => ['degraded']]], ['status' => 'performance_limited', 'reason' => 'RAID md1 degraded', 'array' => 'md1']],
            [[['array' => 'md2', 'severity' => 'ok', 'flags' => []]], null],
        ] as [$raid, $expected]) {
            $this->assertSame($expected, \pmssStorageHealthPerformanceStatus($raid));
        }
    }
}
