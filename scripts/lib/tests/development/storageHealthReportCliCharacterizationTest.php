<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class StorageHealthReportCliCharacterizationTest extends TestCase
{
    private function storageHealthJsonl(array $entries): string
    {
        $jsonPath = $this->pmssMakeTempFile('pmss-storage-health-jsonl-');
        $this->pmssAppendFixtureLines($jsonPath, $entries);
        return $jsonPath;
    }

    private function storageHealthRaidEntry(array $overrides): array
    {
        return array_merge([
            'timestamp' => '2025-01-01T00:00:03+00:00',
            'kind' => 'raid',
            'array' => 'md0',
            'level' => 'raid1',
            'state' => 'active',
        ], $overrides);
    }

    private function runStorageHealthReport(string $jsonPath, array $arguments = []): string
    {
        return $this->pmssRunRepoPhpScript('scripts/storageHealth.php', array_merge(['--json', $jsonPath], $arguments));
    }

    public function testOnlyProblemsAndDeviceFilterKeepLatestMatchingEntry(): void
    {
        $jsonPath = $this->storageHealthJsonl([
            [
                'timestamp' => '2025-01-01T00:00:00+00:00',
                'kind' => 'smart',
                'device' => '/dev/sda',
                'kname' => 'sda',
                'size' => '1T',
                'model' => 'Healthy Disk',
                'severity' => 'ok',
                'flags' => [],
                'metrics' => ['health' => 'PASSED', 'temp_c' => 34],
            ],
            [
                'timestamp' => '2025-01-01T00:00:01+00:00',
                'kind' => 'smart',
                'device' => '/dev/sdb',
                'kname' => 'sdb',
                'size' => '2T',
                'model' => 'Broken Disk',
                'severity' => 'fail',
                'flags' => ['pending_sectors'],
                'metrics' => ['health' => 'FAILED', 'temp_c' => 52, 'pending' => 3],
            ],
            [
                'timestamp' => '2025-01-01T00:00:02+00:00',
                'kind' => 'raid',
                'array' => 'md0',
                'level' => 'raid1',
                'state' => 'active',
                'detail' => 'sda1[0] sdb1[1]',
                'severity' => 'ok',
                'flags' => [],
            ],
        ]);

        $output = $this->runStorageHealthReport($jsonPath, ['--device', 'sdb', '--only-problems']);

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
        $jsonPath = $this->storageHealthJsonl([
            [
                'timestamp' => '2025-01-01T00:00:01+00:00',
                'kind' => 'nvme',
                'device' => '/dev/nvme0n1',
                'kname' => 'nvme0n1',
                'size' => '1T',
                'model' => 'Fast Flash',
                'severity' => 'warn',
                'flags' => ['wearout_high'],
                'metrics' => ['temperature' => 61, 'media_errors' => 0, 'percentage_used' => 85],
            ],
            [
                'timestamp' => '2025-01-01T00:00:02+00:00',
                'kind' => 'smart',
                'device' => '/dev/sdb',
                'kname' => 'sdb',
                'size' => '2T',
                'model' => 'Healthy Disk',
                'severity' => 'ok',
                'flags' => [],
                'metrics' => ['health' => 'PASSED', 'temp_c' => 34],
            ],
            [
                'timestamp' => '2025-01-01T00:00:03+00:00',
                'kind' => 'smart',
                'device' => '/dev/sda',
                'kname' => 'sda',
                'size' => '4T',
                'model' => 'Failing Disk',
                'severity' => 'fail',
                'flags' => ['pending_sectors'],
                'metrics' => ['health' => 'FAILED', 'temp_c' => 48, 'pending' => 7],
            ],
        ]);

        $output = $this->runStorageHealthReport($jsonPath);

        $this->assertOrderedStrings(
            ['Failing Disk', 'Fast Flash', 'Healthy Disk'],
            $output,
            'Expected disk in output: ',
            'Expected fail/warn/ok ordering across mixed disk kinds: '
        );
        $this->assertStringContainsString('NVME', $output);
        $this->assertTrue(
            (bool) preg_match('/Fast Flash.*NVME.*61C.*0.*85/', $output),
            'Expected NVMe rows to keep temperature, media-error, and wear columns aligned'
        );
    }

    public function testUserNoticeWritesPerformanceLimitedPayload(): void
    {
        $noticePath = $this->pmssMakeTempPath('pmss-storage-health-notice-', '.json');

        $jsonPath = $this->storageHealthJsonl([$this->storageHealthRaidEntry([
            'detail' => 'recovery = 1.0% (1/100)',
            'severity' => 'warn',
            'flags' => ['rebuild_in_progress'],
            'operation' => 'recovery',
        ])]);

        $this->runStorageHealthReport($jsonPath, ['--user-notice='.(string) $noticePath]);

        $this->assertTrue(is_file($noticePath), 'Expected user notice file to be created');
        $notice = $this->pmssReadJsonArrayFile($noticePath, null, 'Expected user notice to contain JSON');
        $this->assertEquals('performance_limited', $notice['status'] ?? null, 'Expected performance-limited notice status');
        $this->assertEquals('md0', $notice['array'] ?? null, 'Expected notice to preserve the affected array');
        $this->assertEquals('RAID md0 recovery in progress', $notice['reason'] ?? null, 'Expected notice reason to match rebuild status');
    }

    public function testUserNoticeAcceptsSeparatePathArgument(): void
    {
        $noticePath = $this->pmssMakeTempPath('pmss-storage-health-separate-', '.json');

        $jsonPath = $this->storageHealthJsonl([$this->storageHealthRaidEntry([
            'array' => 'md1',
            'detail' => 'check = 1.0% (1/100)',
            'severity' => 'warn',
            'flags' => ['rebuild_in_progress'],
            'operation' => 'check',
        ])]);

        $this->runStorageHealthReport($jsonPath, ['--user-notice', (string) $noticePath]);

        $this->assertTrue(is_file($noticePath), 'Expected separate-path user notice file to be created');
        $notice = $this->pmssReadJsonArrayFile($noticePath, null, 'Expected separate-path user notice to contain JSON');
        $this->assertEquals('md1', $notice['array'] ?? null, 'Expected separate-path notice to keep the array name');
        $this->assertEquals('RAID md1 check in progress', $notice['reason'] ?? null, 'Expected separate-path notice reason to match the activity');
    }

    public function testUserNoticeClearsStaleFileWhenPerformanceReturnsToNormal(): void
    {
        $noticePath = $this->pmssMakeTempFile('pmss-storage-health-notice-');

        $jsonPath = $this->storageHealthJsonl([$this->storageHealthRaidEntry([
            'detail' => 'sda1[0] sdb1[1]',
            'severity' => 'ok',
            'flags' => [],
        ])]);
        $this->pmssWriteFile($noticePath, "stale\n");

        $this->runStorageHealthReport($jsonPath, ['--user-notice='.(string) $noticePath]);

        clearstatcache(true, (string) $noticePath);
        $this->assertFalse(is_file($noticePath), 'Expected stale user notice to be removed');
    }

    public function testUserNoticeRejectsSymlinkTarget(): void
    {
        $realNoticePath = $this->pmssMakeTempFile('pmss-storage-health-real-notice-');
        $linkNoticePath = $this->pmssMakeTempPath('pmss-storage-health-link-notice-', '.json');

        $jsonPath = $this->storageHealthJsonl([$this->storageHealthRaidEntry([
            'detail' => 'recovery = 1.0% (1/100)',
            'severity' => 'warn',
            'flags' => ['rebuild_in_progress'],
            'operation' => 'recovery',
        ])]);
        $this->pmssWriteFile($realNoticePath, "safe\n");
        symlink((string) $realNoticePath, (string) $linkNoticePath);

        $this->runStorageHealthReport($jsonPath, ['--user-notice='.(string) $linkNoticePath]);

        $this->assertTrue(is_link($linkNoticePath), 'Expected symlinked notice path to remain a symlink');
        $this->assertEquals("safe\n", file_get_contents($realNoticePath), 'Expected symlink target to stay untouched');
    }

    public function testRawOutputMatchesLatestEntriesSnapshot(): void
    {
        $smart = ['timestamp'=>'2025-01-01T00:00:01+00:00','kind'=>'smart','device'=>'/dev/sda','kname'=>'sda','severity'=>'ok','flags'=>[],'metrics'=>['health'=>'PASSED']];
        $raid = ['timestamp'=>'2025-01-01T00:00:02+00:00','kind'=>'raid','array'=>'md0','level'=>'raid1','state'=>'active','detail'=>'sda1[0] sdb1[1]','severity'=>'ok','flags'=>[]];
        $jsonPath = $this->storageHealthJsonl([$smart, $raid]);

        $expected = json_encode($smart, JSON_UNESCAPED_SLASHES).PHP_EOL.json_encode($raid, JSON_UNESCAPED_SLASHES).PHP_EOL;
        $actual = $this->runStorageHealthReport($jsonPath, ['--raw']);

        $this->assertSame($expected, $actual);
    }
}
