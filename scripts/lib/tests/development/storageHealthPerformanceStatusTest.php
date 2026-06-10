<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/storageHealth.php';

class StorageHealthPerformanceStatusTest extends TestCase
{
    public function testPerformanceStatusVariants(): void
    {
        foreach ([
            [[['array' => 'md0', 'severity' => 'warn', 'flags' => ['rebuild_in_progress']]], 'resync'],
            [[['array' => 'md1', 'severity' => 'fail', 'flags' => ['degraded']]], 'md1'],
            [[['array' => 'md2', 'severity' => 'ok', 'flags' => []]], null],
        ] as [$raid, $needle]) {
            $status = \pmssStorageHealthPerformanceStatus($raid);
            if ($needle === null) {
                $this->assertTrue($status === null, 'Expected no performance status');
                continue;
            }
            $this->assertTrue($status !== null, 'Expected performance status');
            $this->assertEquals('performance_limited', $status['status']);
            $this->assertStringContainsString($needle, $status['reason']);
        }
    }
}
