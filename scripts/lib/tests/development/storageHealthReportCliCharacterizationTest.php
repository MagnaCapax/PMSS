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

        $output = $this->pmssRunPhpScript(
            dirname(__DIR__, 4).'/scripts/storageHealth.php',
            ['--json', (string) $jsonPath, '--device', 'sdb', '--only-problems']
        );

        $this->assertStringContainsString('Performance status: OK', $output);
        $this->assertStringContainsString('Summary: 0 ok, 0 warn, 1 fail', $output);
        $this->assertStringContainsString('Broken Disk', $output);
        $this->assertStringContainsString('pending_sectors', $output);
        $this->pmssAssertStringNotContainsString('Healthy Disk', $output);
        $this->pmssAssertStringNotContainsString('MD RAID', $output);
        $this->pmssAssertStringNotContainsString(' sda ', $output);
    }

    public function testMixedSmartAndNvmeDisksShareSeverityOrdering(): void
    {
        $jsonPath = $this->pmssMakeTempFile('pmss-storage-health-jsonl-');
        $this->assertTrue($jsonPath !== false, 'Expected a JSONL fixture path');

        file_put_contents((string) $jsonPath, implode("\n", [
            json_encode([
                'timestamp' => '2025-01-01T00:00:01+00:00',
                'kind' => 'nvme',
                'device' => '/dev/nvme0n1',
                'kname' => 'nvme0n1',
                'size' => '1T',
                'model' => 'Fast Flash',
                'severity' => 'warn',
                'flags' => ['wearout_high'],
                'metrics' => ['temperature' => 61, 'media_errors' => 0, 'percentage_used' => 85],
            ], JSON_UNESCAPED_SLASHES),
            json_encode([
                'timestamp' => '2025-01-01T00:00:02+00:00',
                'kind' => 'smart',
                'device' => '/dev/sdb',
                'kname' => 'sdb',
                'size' => '2T',
                'model' => 'Healthy Disk',
                'severity' => 'ok',
                'flags' => [],
                'metrics' => ['health' => 'PASSED', 'temp_c' => 34],
            ], JSON_UNESCAPED_SLASHES),
            json_encode([
                'timestamp' => '2025-01-01T00:00:03+00:00',
                'kind' => 'smart',
                'device' => '/dev/sda',
                'kname' => 'sda',
                'size' => '4T',
                'model' => 'Failing Disk',
                'severity' => 'fail',
                'flags' => ['pending_sectors'],
                'metrics' => ['health' => 'FAILED', 'temp_c' => 48, 'pending' => 7],
            ], JSON_UNESCAPED_SLASHES),
        ])."\n");

        $output = $this->pmssRunPhpScript(dirname(__DIR__, 4).'/scripts/storageHealth.php', ['--json', (string) $jsonPath]);

        $failPos = strpos($output, 'Failing Disk');
        $warnPos = strpos($output, 'Fast Flash');
        $okPos = strpos($output, 'Healthy Disk');

        $this->assertTrue($failPos !== false, 'Expected SMART fail disk in output');
        $this->assertTrue($warnPos !== false, 'Expected NVMe warn disk in output');
        $this->assertTrue($okPos !== false, 'Expected SMART ok disk in output');
        $this->assertTrue($failPos < $warnPos && $warnPos < $okPos, 'Expected fail/warn/ok ordering across mixed disk kinds');
        $this->assertStringContainsString('NVME', $output);
        $this->assertTrue(
            (bool) preg_match('/Fast Flash.*NVME.*61C.*0.*85/', $output),
            'Expected NVMe rows to keep temperature, media-error, and wear columns aligned'
        );
    }

    public function testUserNoticeWritesPerformanceLimitedPayload(): void
    {
        $jsonPath = $this->pmssMakeTempFile('pmss-storage-health-jsonl-');
        $noticePath = $this->pmssMakeTempPath('pmss-storage-health-notice-', '.json');

        file_put_contents((string) $jsonPath, json_encode([
            'timestamp' => '2025-01-01T00:00:03+00:00',
            'kind' => 'raid',
            'array' => 'md0',
            'level' => 'raid1',
            'state' => 'active',
            'detail' => 'recovery = 1.0% (1/100)',
            'severity' => 'warn',
            'flags' => ['rebuild_in_progress'],
            'operation' => 'recovery',
        ], JSON_UNESCAPED_SLASHES)."\n");

        $this->pmssRunPhpScript(
            dirname(__DIR__, 4).'/scripts/storageHealth.php',
            ['--json', (string) $jsonPath, '--user-notice='.(string) $noticePath]
        );

        $this->assertTrue(is_file($noticePath), 'Expected user notice file to be created');
        $notice = json_decode((string) file_get_contents($noticePath), true);
        $this->assertTrue(is_array($notice), 'Expected user notice to contain JSON');
        $this->assertEquals('performance_limited', $notice['status'] ?? null, 'Expected performance-limited notice status');
        $this->assertEquals('md0', $notice['array'] ?? null, 'Expected notice to preserve the affected array');
        $this->assertEquals('RAID md0 recovery in progress', $notice['reason'] ?? null, 'Expected notice reason to match rebuild status');
    }

    public function testUserNoticeAcceptsSeparatePathArgument(): void
    {
        $jsonPath = $this->pmssMakeTempFile('pmss-storage-health-jsonl-');
        $noticePath = $this->pmssMakeTempPath('pmss-storage-health-separate-', '.json');

        file_put_contents((string) $jsonPath, json_encode([
            'timestamp' => '2025-01-01T00:00:03+00:00',
            'kind' => 'raid',
            'array' => 'md1',
            'level' => 'raid1',
            'state' => 'active',
            'detail' => 'check = 1.0% (1/100)',
            'severity' => 'warn',
            'flags' => ['rebuild_in_progress'],
            'operation' => 'check',
        ], JSON_UNESCAPED_SLASHES)."\n");

        $this->pmssRunPhpScript(
            dirname(__DIR__, 4).'/scripts/storageHealth.php',
            ['--json', (string) $jsonPath, '--user-notice', (string) $noticePath]
        );

        $this->assertTrue(is_file($noticePath), 'Expected separate-path user notice file to be created');
        $notice = json_decode((string) file_get_contents($noticePath), true);
        $this->assertTrue(is_array($notice), 'Expected separate-path user notice to contain JSON');
        $this->assertEquals('md1', $notice['array'] ?? null, 'Expected separate-path notice to keep the array name');
        $this->assertEquals('RAID md1 check in progress', $notice['reason'] ?? null, 'Expected separate-path notice reason to match the activity');
    }

    public function testUserNoticeClearsStaleFileWhenPerformanceReturnsToNormal(): void
    {
        $jsonPath = $this->pmssMakeTempFile('pmss-storage-health-jsonl-');
        $noticePath = $this->pmssMakeTempFile('pmss-storage-health-notice-');

        file_put_contents((string) $jsonPath, json_encode([
            'timestamp' => '2025-01-01T00:00:03+00:00',
            'kind' => 'raid',
            'array' => 'md0',
            'level' => 'raid1',
            'state' => 'active',
            'detail' => 'sda1[0] sdb1[1]',
            'severity' => 'ok',
            'flags' => [],
        ], JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents((string) $noticePath, "stale\n");

        $this->pmssRunPhpScript(
            dirname(__DIR__, 4).'/scripts/storageHealth.php',
            ['--json', (string) $jsonPath, '--user-notice='.(string) $noticePath]
        );

        $this->assertFalse(is_file($noticePath), 'Expected stale user notice to be removed');
    }

    public function testUserNoticeRejectsSymlinkTarget(): void
    {
        $jsonPath = $this->pmssMakeTempFile('pmss-storage-health-jsonl-');
        $realNoticePath = $this->pmssMakeTempFile('pmss-storage-health-real-notice-');
        $linkNoticePath = $this->pmssMakeTempPath('pmss-storage-health-link-notice-', '.json');

        file_put_contents((string) $jsonPath, json_encode([
            'timestamp' => '2025-01-01T00:00:03+00:00',
            'kind' => 'raid',
            'array' => 'md0',
            'level' => 'raid1',
            'state' => 'active',
            'detail' => 'recovery = 1.0% (1/100)',
            'severity' => 'warn',
            'flags' => ['rebuild_in_progress'],
            'operation' => 'recovery',
        ], JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents((string) $realNoticePath, "safe\n");
        symlink((string) $realNoticePath, (string) $linkNoticePath);

        $this->pmssRunPhpScript(
            dirname(__DIR__, 4).'/scripts/storageHealth.php',
            ['--json', (string) $jsonPath, '--user-notice='.(string) $linkNoticePath]
        );

        $this->assertTrue(is_link($linkNoticePath), 'Expected symlinked notice path to remain a symlink');
        $this->assertEquals("safe\n", file_get_contents($realNoticePath), 'Expected symlink target to stay untouched');
    }
}
