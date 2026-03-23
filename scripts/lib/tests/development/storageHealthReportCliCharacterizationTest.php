<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class StorageHealthReportCliCharacterizationTest extends TestCase
{
    public function testOnlyProblemsAndDeviceFilterKeepLatestMatchingEntry(): void
    {
        $jsonPath = $this->pmssMakeTempFile('pmss-storage-health-jsonl-');
        $this->assertTrue($jsonPath !== false, 'Expected a JSONL fixture path');

        file_put_contents((string) $jsonPath, implode("\n", [
            json_encode([
                'timestamp' => '2025-01-01T00:00:00+00:00',
                'kind' => 'smart',
                'device' => '/dev/sda',
                'kname' => 'sda',
                'size' => '1T',
                'model' => 'Healthy Disk',
                'severity' => 'ok',
                'flags' => [],
                'metrics' => ['health' => 'PASSED', 'temp_c' => 34],
            ], JSON_UNESCAPED_SLASHES),
            json_encode([
                'timestamp' => '2025-01-01T00:00:01+00:00',
                'kind' => 'smart',
                'device' => '/dev/sdb',
                'kname' => 'sdb',
                'size' => '2T',
                'model' => 'Broken Disk',
                'severity' => 'fail',
                'flags' => ['pending_sectors'],
                'metrics' => ['health' => 'FAILED', 'temp_c' => 52, 'pending' => 3],
            ], JSON_UNESCAPED_SLASHES),
            json_encode([
                'timestamp' => '2025-01-01T00:00:02+00:00',
                'kind' => 'raid',
                'array' => 'md0',
                'level' => 'raid1',
                'state' => 'active',
                'detail' => 'sda1[0] sdb1[1]',
                'severity' => 'ok',
                'flags' => [],
            ], JSON_UNESCAPED_SLASHES),
        ])."\n");

        $script = dirname(__DIR__, 4).'/scripts/storageHealth.php';
        $command = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg($script)
            .' --json '.escapeshellarg((string) $jsonPath)
            .' --device sdb --only-problems 2>&1';
        $output = (string) shell_exec($command);

        $this->assertStringContainsString('Performance status: OK', $output);
        $this->assertStringContainsString('Summary: 0 ok, 0 warn, 1 fail', $output);
        $this->assertStringContainsString('Broken Disk', $output);
        $this->assertStringContainsString('pending_sectors', $output);
        $this->pmssAssertStringNotContainsString('Healthy Disk', $output);
        $this->pmssAssertStringNotContainsString('MD RAID', $output);
        $this->pmssAssertStringNotContainsString(' sda ', $output);
    }
}
