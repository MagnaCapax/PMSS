<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/storageHealth.php';

/**
 * Host /home and / free-space records in the storage-health stream: an oversubscribed array that
 * reaches 100% gives every account ENOSPC while each account's quota panel still shows headroom.
 */
class StorageHealthSpaceTest extends TestCase
{
    // `stat -f -c '%S %b %f %a %c %d'`: block size, blocks, root-free blocks, available blocks, inodes, free inodes.
    private const STAT_OK = "4096 1000 200 100 1000 900\n";

    public function testParseReportsAvailableSpaceTheWayDfDoes(): void
    {
        // 800 used + 100 available = 900 usable blocks; 100/900 = 11.1% free.
        $this->assertSame(
            ['total_bytes' => 900 * 4096, 'free_bytes' => 100 * 4096, 'free_percent' => 11.1, 'inode_free_percent' => 90.0],
            \pmssStorageHealthSpaceParse(self::STAT_OK)
        );
    }

    public function testParseRejectsGarbageAndEmptyFilesystems(): void
    {
        foreach (['', 'garbage', "4096 1000 200 100 1000\n", "0 1000 200 100 1000 900\n", "4096 0 0 0 10 10\n"] as $output) {
            $this->assertSame(null, \pmssStorageHealthSpaceParse($output), var_export($output, true));
        }
        // A filesystem without a fixed inode table has no inode percentage to judge.
        $this->assertSame(null, \pmssStorageHealthSpaceParse("4096 1000 200 100 0 0\n")['inode_free_percent']);
    }

    public function testBlockFreeSpaceBelowTenPercentWarns(): void
    {
        $severity = static function (string $stat): string { return \pmssStorageHealthSpaceSeverity(\pmssStorageHealthSpaceParse($stat)); };
        $this->assertSame('ok', $severity("4096 1000 200 100 1000 900\n"));   // 11.1% free
        $this->assertSame('warn', $severity("4096 1000 200 80 1000 900\n"));  // 9.1% free
    }

    public function testBlockFreeSpaceBelowThreePercentFails(): void
    {
        $severity = static function (string $stat): string { return \pmssStorageHealthSpaceSeverity(\pmssStorageHealthSpaceParse($stat)); };
        $this->assertSame('fail', $severity("4096 1000 200 10 1000 900\n"));  // 1.2% free
        $this->assertSame('warn', $severity("4096 1000 200 30 1000 900\n"));  // 3.2% free is still only a warning
    }

    public function testInodeExhaustionDrivesSeverityWhenBlocksAreFree(): void
    {
        $severity = static function (string $stat): string { return \pmssStorageHealthSpaceSeverity(\pmssStorageHealthSpaceParse($stat)); };
        $this->assertSame('fail', $severity("4096 1000 200 500 1000 20\n"));  // blocks fine, inodes 2.0% free
        $this->assertSame('warn', $severity("4096 1000 200 500 1000 80\n"));  // inodes 8.0% free
    }

    public function testSnapshotRecordsHomeAndRootAsFsEntries(): void
    {
        $commands = [];
        $capture = static function (string $command, int $timeout) use (&$commands): array {
            $commands[] = $command;
            return ['rc' => 0, 'stdout' => strpos($command, "'/home'") !== false ? "4096 1000 200 10 1000 900\n" : self::STAT_OK, 'stderr' => ''];
        };
        $deviceId = static function (string $path): int { return $path === '/home' ? 2049 : 1; };

        $entries = \pmssStorageHealthSnapshotSpace('2025-01-01T00:00:00+00:00', ['/home', '/'], $capture, $deviceId);

        $this->assertSame(['/home', '/'], array_column($entries, 'device'));
        $this->assertSame(['fs', 'fs'], array_column($entries, 'kind'));
        $this->assertSame(['fail', 'ok'], array_column($entries, 'severity'));
        $this->assertSame(['low_free_space'], $entries[0]['flags']);
        $this->assertSame(1.2, $entries[0]['metrics']['free_percent']);
        $this->assertSame(10 * 4096, $entries[0]['metrics']['free_bytes']);
        $this->assertSame(["stat -f -c '%S %b %f %a %c %d' '/home'", "stat -f -c '%S %b %f %a %c %d' '/'"], $commands);
    }

    public function testHomeSharingTheRootDeviceIsNotReportedWithRootFigures(): void
    {
        $called = [];
        $capture = static function (string $command, int $timeout) use (&$called): array {
            $called[] = $command;
            return ['rc' => 0, 'stdout' => self::STAT_OK, 'stderr' => ''];
        };

        $entries = \pmssStorageHealthSnapshotSpace('2025-01-01T00:00:00+00:00', ['/home'], $capture, static function (string $path): int { return 7; });

        // A /home that failed to mount would otherwise be "monitored" through the root filesystem's numbers.
        $this->assertSame('warn', $entries[0]['severity']);
        $this->assertSame(['home_not_separate_mount'], $entries[0]['flags']);
        $this->assertSame([], $entries[0]['metrics']);
        $this->assertSame([], $called);
    }

    public function testUnreadableFilesystemIsAWarningNotASilentGap(): void
    {
        $failing = static function (string $command, int $timeout): array { return ['rc' => 1, 'stdout' => '', 'stderr' => 'stat: cannot read']; };
        $entries = \pmssStorageHealthSnapshotSpace('2025-01-01T00:00:00+00:00', ['/'], $failing, static function (string $path): int { return 1; });

        $this->assertSame('warn', $entries[0]['severity']);
        $this->assertSame(['space_unreadable'], $entries[0]['flags']);
    }

    public function testTimedOutReadIsRecordedAsUnreadableAndDoesNotAbortTheOtherMount(): void
    {
        // pmssCommandCapture reports a killed probe with rc 124: a hung /home must not hide / or stop the run.
        $capture = static function (string $command, int $timeout): array {
            return strpos($command, "'/home'") !== false
                ? ['rc' => 124, 'stdout' => '', 'stderr' => 'timed out']
                : ['rc' => 0, 'stdout' => self::STAT_OK, 'stderr' => ''];
        };
        $entries = \pmssStorageHealthSnapshotSpace('2025-01-01T00:00:00+00:00', ['/home', '/'], $capture, static function (string $path): int { return $path === '/home' ? 2 : 1; });

        $this->assertSame(['warn', 'ok'], array_column($entries, 'severity'));
        $this->assertSame(['space_unreadable'], $entries[0]['flags']);
        $this->assertSame([], $entries[0]['metrics']);
        $this->assertSame(11.1, $entries[1]['metrics']['free_percent']);
    }

    public function testSpaceOnlyRerunAppendsAnotherPairWithoutRewritingHistory(): void
    {
        $jsonPath = $this->pmssMakeTempPath('pmss-storage-space-rerun-', '.jsonl');
        $this->pmssWriteFile($jsonPath, '{"timestamp":"2025-01-01T00:00:00+00:00","kind":"smart","device":"/dev/sda","severity":"ok"}'."\n");

        for ($run = 0; $run < 2; $run++) {
            $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/storageHealthSnapshot.php', ['--json', $jsonPath, '--space-only', '--quiet']);
            $this->assertSame(0, $result['rc'], $result['output']);
        }

        $kinds = array();
        foreach (file($jsonPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $kinds[] = $this->pmssDecodeJsonArray($line)['kind'] ?? '';
        }
        // The earlier SMART record is untouched and each run adds exactly the /home and / pair.
        $this->assertSame(array('smart', 'fs', 'fs', 'fs', 'fs'), $kinds);
    }

    public function testReportKeepsDeviceSnapshotTimestampWhenNewerSpaceRecordsExist(): void
    {
        $jsonPath = $this->pmssMakeTempFile('pmss-storage-health-jsonl-');
        $this->pmssAppendFixtureLines($jsonPath, [
            ['timestamp' => '2025-01-01T06:00:00+00:00', 'kind' => 'smart', 'device' => '/dev/sda', 'kname' => 'sda', 'size' => '1T', 'model' => 'Disk', 'severity' => 'ok', 'flags' => [], 'metrics' => ['health' => 'PASSED']],
            ['timestamp' => '2025-01-01T09:13:00+00:00', 'kind' => 'fs', 'device' => '/', 'severity' => 'ok', 'flags' => [], 'metrics' => ['free_bytes' => 1000000000, 'free_percent' => 50.0, 'inode_free_percent' => 90.0]],
        ]);

        $output = $this->pmssRunRepoPhpScript('scripts/storageHealth.php', ['--json', $jsonPath]);

        $this->assertStringContainsString('latest snapshot 2025-01-01T06:00:00+00:00', $output);
    }

    public function testSpaceOnlySnapshotWritesFsEntriesWithoutProbingDevices(): void
    {
        $bin = $this->pmssMakeTempDir('pmss-storage-space-bin-', 0700);
        $marker = $bin.'/lsblk-ran';
        $this->pmssWriteExecutableFiles($bin, ['lsblk' => "#!/bin/sh\ntouch ".escapeshellarg($marker)."\nexit 0\n"]);
        $jsonPath = $this->pmssMakeTempPath('pmss-storage-space-', '.jsonl');

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/storageHealthSnapshot.php', ['--json', $jsonPath, '--space-only', '--quiet'],
            $this->pmssPathPrefixedEnvironment($bin));

        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertTrue(!is_file($marker), 'space-only run must not run the device inventory');
        $kinds = [];
        foreach (file($jsonPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $kinds[] = $this->pmssDecodeJsonArray($line)['kind'] ?? '';
        }
        $this->assertSame(['fs', 'fs'], $kinds);
    }

    public function testReportListsFilesystemSpaceAndOnlyProblemsHidesHealthyMounts(): void
    {
        $jsonPath = $this->pmssMakeTempFile('pmss-storage-health-jsonl-');
        $this->pmssAppendFixtureLines($jsonPath, [
            ['timestamp' => '2025-01-01T00:00:00+00:00', 'kind' => 'fs', 'device' => '/home', 'severity' => 'warn', 'flags' => ['low_free_space'],
                'metrics' => ['total_bytes' => 8000000000000, 'free_bytes' => 640000000000, 'free_percent' => 8.0, 'inode_free_percent' => 91.5]],
            ['timestamp' => '2025-01-01T00:00:00+00:00', 'kind' => 'fs', 'device' => '/', 'severity' => 'ok', 'flags' => [],
                'metrics' => ['total_bytes' => 100000000000, 'free_bytes' => 60000000000, 'free_percent' => 60.0, 'inode_free_percent' => 95.0]],
        ]);

        $all = $this->pmssRunRepoPhpScript('scripts/storageHealth.php', ['--json', $jsonPath]);
        $this->assertStringContainsAllStrings(['Filesystem space', '/home', '640 GB', '8%', 'low_free_space', '95%'], $all);

        $problems = $this->pmssRunRepoPhpScript('scripts/storageHealth.php', ['--json', $jsonPath, '--only-problems']);
        $this->assertStringContainsString('low_free_space', $problems);
        $this->assertStringNotContainsString('95%', $problems);
    }
}
