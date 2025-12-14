<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/storageHealth.php';

class StorageHealthPerformanceStatusTest extends TestCase
{
    public function testDetectsResyncAsPerformanceLimited(): void
    {
        $raid = [
            ['array' => 'md0', 'severity' => 'warn', 'flags' => ['rebuild_in_progress']],
        ];
        $status = \pmssStorageHealthPerformanceStatus($raid);
        $this->assertTrue($status !== null, 'Expected performance status');
        $this->assertEquals('performance_limited', $status['status']);
        $this->assertStringContainsString('resync', $status['reason']);
    }

    public function testDetectsDegradedArray(): void
    {
        $raid = [
            ['array' => 'md1', 'severity' => 'fail', 'flags' => ['degraded']],
        ];
        $status = \pmssStorageHealthPerformanceStatus($raid);
        $this->assertTrue($status !== null, 'Expected performance status');
        $this->assertStringContainsString('md1', $status['reason']);
    }

    public function testNoRaidIssuesReturnsNull(): void
    {
        $raid = [
            ['array' => 'md2', 'severity' => 'ok', 'flags' => []],
        ];
        $status = \pmssStorageHealthPerformanceStatus($raid);
        $this->assertTrue($status === null, 'Expected no performance status');
    }
}

