<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/storageHealth.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/storageHealthNotice.php';

class StorageHealthHomeRaidActivityTest extends TestCase
{
    /** @var string */
    private $tmpDir;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tmpDir', 'pmss-storage-health-home-', 0700);
        $this->pmssEnsureDir($this->tmpDir.'/sys/class/block', 0700);
        $this->pmssEnsureDir($this->tmpDir.'/dev/mapper', 0700);
    }

    public function testParsesRaidActivitySummaryDetails(): void
    {
        $summary = \pmssStorageHealthRaidActivitySummaryParse(
            '[==>..................]  check = 12.3% (120/999) finish=15.4min speed=123456K/sec'
        );

        $this->assertSame(['operation' => 'check', 'progress' => '12.3%', 'eta' => '15.4min', 'speed' => '123456K/sec'], $summary);
    }

    public function testRaidEntriesParserKeepsDegradedRebuildShape(): void
    {
        $entries = \pmssStorageHealthRaidEntriesParse(
            "md1 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/1] [U_]\n".
            "      [>....................]  recovery = 7.5% finish=60.0min speed=2000K/sec\n",
            '2025-01-01T00:00:00+00:00'
        );

        $this->assertEquals(1, count($entries));
        $this->pmssAssertArraySubsetSame(
            ['timestamp' => '2025-01-01T00:00:00+00:00', 'kind' => 'raid', 'array' => 'md1', 'level' => 'raid1', 'state' => 'active', 'severity' => 'fail', 'ok' => false, 'flags' => ['degraded', 'rebuild_in_progress'], 'operation' => 'recovery'],
            $entries[0]
        );
    }

    public function testResolvesHomeArrayFromDirectMdMount(): void
    {
        $homeArray = \pmssStorageHealthHomeArrayResolve($this->pmssWriteRelativeFile($this->tmpDir, 'mounts', "/dev/md0 /home ext4 rw 0 0\n", 0700));
        $this->assertEquals('md0', $homeArray);
    }

    public function testRaidSnapshotPathFailuresStayEmpty(): void
    {
        $path = $this->pmssWriteRelativeFile($this->tmpDir, 'mdstat', "md1 : active raid1 sda1[0]\n", 0700);
        foreach (['', "\0", $path."\0suffix", "\0".$path, $this->tmpDir.'/missing'] as $invalid) {
            $this->assertSame([], \pmssStorageHealthSnapshotRaid('fixture', $invalid));
        }
        $stub = $this->pmssMakeExecutableStub('mdadm', "#!/bin/sh\nprintf 'Raid Level : raid1\nIntent Bitmap : Internal\nState : clean\n'\n", 'pmss-raid-path-bin-');
        $this->pmssWithEnv(['PATH' => $stub], function () use ($path): void {
            $expected = \pmssStorageHealthRaidEntriesParse("md1 : active raid1 sda1[0]\n", 'fixture');
            $expected[0]['write_intent_bitmap'] = true;
            $this->assertSame($expected, \pmssStorageHealthSnapshotRaid('fixture', $path));
        });
        $this->assertSame([], \pmssStorageHealthSnapshotRaid('fixture', $this->pmssWriteFile($path, '')));
    }

    public function testHomeArrayPathFailuresStayNull(): void
    {
        $path = $this->pmssWriteRelativeFile($this->tmpDir, 'mounts', "/dev/md1 /home ext4 rw 0 0\n", 0700);
        foreach (["\0", $path."\0suffix", "\0".$path, $this->tmpDir.'/missing'] as $invalid) {
            $this->assertSame(null, \pmssStorageHealthHomeArrayResolve($invalid));
        }
        $this->assertSame('md1', \pmssStorageHealthHomeArrayResolve($path));
        $this->assertSame(null, \pmssStorageHealthHomeArrayResolve($this->pmssWriteFile($path, '')));
    }

    /** Raw input boundaries in scripts/lib/storageHealth/raid.php must precede probing. */
    public function testRaidSnapshotRejectsCorruptTextBeforeProbes(): void
    {
        $row = "md1 : active raid1 sda1[0] [2/2] [UU]\n";
        $path = $this->pmssWriteRelativeFile($this->tmpDir, 'mdstat', '', 0700);
        $calls = $this->pmssMakeTempFile('pmss-raid-text-probes-');
        $stub = $this->pmssMakeExecutableStub('mdadm', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> ".escapeshellarg($calls)."\n".
            "printf 'Raid Level : raid1\\nIntent Bitmap : Internal\\nState : clean\\n'\n", 'pmss-raid-text-bin-');
        $this->pmssWithEnv(['PATH' => $stub], function () use ($row, $path, $calls): void {
            foreach (["\0".$row, $row."\0", str_replace('sda1', "sd\0a1", $row),
                str_replace('[UU]', "[U\0U]", $row), $row."\0md2 : active raid1 sdb1[0]\n",
                $row." recovery = 7.5%\0\n"] as $raw) {
                $this->pmssWriteFile($path, $raw);
                $this->assertSame([], \pmssStorageHealthSnapshotRaid('fixture', $path));
            }
            // Refusals must not launch mdadm, even when a valid sibling array was present.
            $this->assertSame('', file_get_contents($calls));
            foreach ([$row, " \t".$row, str_replace("\n", "\r\n", $row)] as $raw) {
                $this->pmssWriteFile($path, $raw);
                $expected = \pmssStorageHealthRaidEntriesParse($raw, 'fixture');
                $expected[0]['write_intent_bitmap'] = true;
                $this->assertSame($expected, \pmssStorageHealthSnapshotRaid('fixture', $path));
            }
            $this->assertSame(str_repeat("--detail /dev/md1\n", 3), file_get_contents($calls));
        });
    }

    public function testHomeArrayRejectsCorruptTextBeforeNormalization(): void
    {
        $row = "/dev/md1 /home ext4 rw 0 0\n";
        $path = $this->pmssWriteRelativeFile($this->tmpDir, 'mounts', '', 0700);
        foreach (["\0".$row, $row."\0", str_replace('md1', "m\0d1", $row),
            str_replace('/home', "/home\0", $row), $row."\0/dev/md2 /other ext4 rw 0 0\n",
            "/dev/md2 /other ext4 rw\0 0 0\n".$row] as $raw) {
            $this->pmssWriteFile($path, $raw);
            $this->assertSame(null, \pmssStorageHealthHomeArrayResolve($path));
            $this->assertSame(null, \pmssStorageHealthHomeRaidActivity($path, []));
        }
        // Whitespace, CRLF and unrelated valid rows keep direct-md resolution intact.
        foreach ([$row, " \t".$row, str_replace("\n", "\r\n", $row),
            "/dev/vda1 / ext4 rw 0 0\n".$row, $row."/dev/md2 /other ext4 rw 0 0\n"] as $raw) {
            $this->assertSame('md1', \pmssStorageHealthHomeArrayResolve($this->pmssWriteFile($path, $raw)));
        }
    }

    public function testHomeArrayRejectsNulInsideMountSource(): void
    {
        foreach (["/dev/\0md1", "/dev/md1\0suffix", "/dev/md\0/../md1"] as $source) {
            $path = $this->pmssWriteRelativeFile($this->tmpDir, 'mounts', $source." /home ext4 rw 0 0\n", 0700);
            $this->assertSame(null, \pmssStorageHealthHomeArrayResolve($path));
            $this->assertSame(null, \pmssStorageHealthHomeRaidActivity($path, []));
        }
    }

    public function testResolvesHomeArrayFromNamedMdDevice(): void
    {
        $this->pmssWriteRelativeFile($this->tmpDir, 'dev/md7', '', 0700);
        $this->pmssWriteSymlinks($this->tmpDir, ['dev/md/home' => '../md7'], 0700);

        $mountsPath = $this->pmssWriteRelativeFile($this->tmpDir, 'mounts', $this->tmpDir.'/dev/md/home /home ext4 rw 0 0'."\n", 0700);

        $homeArray = \pmssStorageHealthHomeArrayResolve($mountsPath);
        $this->assertEquals('md7', $homeArray);
    }

    public function testHomeRaidActivitySelectsHomeArrayStateVariants(): void
    {
        foreach ([
            [
                [
                    ['array' => 'md0', 'resync' => '      [>....................]  resync = 5.1% finish=90.0min speed=1000K/sec'],
                    ['array' => 'md1', 'resync' => '      [>....................]  recovery = 7.5% finish=60.0min speed=2000K/sec'],
                ],
                ['array' => 'md1', 'operation' => 'recovery', 'progress' => '7.5%'],
            ],
            [
                [
                    ['array' => 'md1', 'flags' => ['degraded'], 'severity' => 'fail'],
                ],
                ['array' => 'md1', 'flags' => ['degraded']],
            ],
            [
                [
                    [
                        'array' => 'md1',
                        'flags' => ['degraded', 'rebuild_in_progress'],
                        'severity' => 'fail',
                        'resync' => '      [>....................]  recovery = 7.5% finish=60.0min speed=2000K/sec',
                    ],
                ],
                ['operation' => 'recovery', 'progress' => '7.5%'],
            ],
        ] as [$raidEntries, $expected]) {
            $activity = \pmssStorageHealthHomeRaidActivity($this->pmssWriteRelativeFile($this->tmpDir, 'mounts', "/dev/md1 /home ext4 rw 0 0\n", 0700), $raidEntries);
            $this->assertTrue(is_array($activity), 'Expected activity for /home array');
            $this->pmssAssertArraySubsetSame($expected, $activity);
        }
    }

    public function testHomeRaidActivityReturnsNullWhenHomeIsNotOnMd(): void
    {
        $activity = \pmssStorageHealthHomeRaidActivity($this->pmssWriteRelativeFile($this->tmpDir, 'mounts', "/dev/vda1 /home ext4 rw 0 0\n", 0700), [
            ['array' => 'md1', 'resync' => '      [>....................]  resync = 5.1% finish=90.0min speed=1000K/sec'],
        ]);
        $this->assertTrue($activity === null, 'Expected no activity when /home is not backed by md');
    }

    public function testHomeRaidNoticeHtmlBuildsExpectedVariants(): void
    {
        foreach ([
            [
                ['array' => 'md0', 'operation' => 'check', 'progress' => '12.3%', 'eta' => '15.4min', 'speed' => '123456K/sec'],
                ['Home storage maintenance in progress', 'Progress: 12.3%', 'ETA: 15.4min'],
                [],
            ],
            [
                ['array' => 'md0', 'operation' => 'check'],
                ['Home storage maintenance in progress', 'RAID array md0 is running a check'],
                ['pmss-raid-meta' => 'Meta details should be omitted when no values exist'],
            ],
            [
                ['array' => 'md1', 'flags' => ['degraded']],
                ['Storage array degraded', 'without full redundancy', 'pmss-raid-notice-error'],
                ['Progress:' => 'Degraded notice should not show rebuild metadata'],
            ],
        ] as [$payload, $required, $forbidden]) {
            $this->assertStringContainsAndOmitsStrings($required, $forbidden, \pmssStorageHealthHomeRaidNoticeHtmlBuild($payload));
        }
    }

    private function psiPath(string $full = '4.25'): string
    {
        return $this->pmssWriteRelativeFile($this->tmpDir, 'pressure-'.bin2hex(random_bytes(3)),
            "some avg10=1.00 avg60=2.00 avg300=3.00 total=12\nfull avg10=1.25 avg60=2.50 avg300={$full} total=34\n", 0700);
    }

    public function testHostPressureStateUsesConservativeThresholds(): void
    {
        $now = 2000;
        $healthy = $this->pmssWriteFile($this->tmpDir.'/host-pressure-'.bin2hex(random_bytes(3)).'.json',
            json_encode(['timestamp' => $now, 'ioping_home_ms' => 100.0])."\n");
        $this->assertSame('normal', \pmssStorageHealthHostPressureStateRead($healthy, $now, $this->psiPath('19.9'))['status']);
        $this->assertSame('heavy', \pmssStorageHealthHostPressureStateRead($healthy, $now, $this->psiPath('20.0'))['status']);
        $slow = $this->pmssWriteFile($this->tmpDir.'/host-pressure-'.bin2hex(random_bytes(3)).'.json',
            json_encode(['timestamp' => $now, 'ioping_home_ms' => 100.1])."\n");
        $this->assertSame('heavy', \pmssStorageHealthHostPressureStateRead($slow, $now, $this->psiPath())['status']);
        $this->assertSame(4.25, \pmssStorageHealthHostPressureStateRead($slow, $now, $this->psiPath())['psi_io_full_avg300']);
        $this->assertSame('absent', \pmssStorageHealthHostPressureStateRead($slow, $now, $this->tmpDir.'/missing')['status']);
        $this->assertSame('', \pmssStorageHealthStatusRowHtmlBuild(['status' => 'absent']));
        $this->assertSame('unavailable', \pmssStorageHealthHostPressureStateRead($slow, $now, $this->tmpDir)['status']);
        $this->assertSame('unavailable', \pmssStorageHealthHostPressureStateRead($slow, $now, $this->psiPath('broken'))['status']);
    }

    public function testHostPressureStateRejectsStaleMalformedAndUnsafeSnapshots(): void
    {
        $now = 5000;
        foreach ([
            $this->pmssWriteFile($this->tmpDir.'/host-pressure-'.bin2hex(random_bytes(3)).'.json',
                json_encode(['timestamp' => $now - 901, 'ioping_home_ms' => 500])."\n"),
            $this->pmssWriteFile($this->tmpDir.'/host-pressure-'.bin2hex(random_bytes(3)).'.json',
                json_encode(['timestamp' => $now + 1, 'ioping_home_ms' => 500])."\n"),
            $this->pmssWriteFile($this->tmpDir.'/malformed.json', '{broken'),
        ] as $path) {
            $state = \pmssStorageHealthHostPressureStateRead($path, $now, $this->psiPath());
            $this->assertSame('normal', $state['status']);
            $this->assertFalse(isset($state['ioping_home_ms']));
        }

        $target = $this->pmssWriteFile($this->tmpDir.'/host-pressure-'.bin2hex(random_bytes(3)).'.json',
            json_encode(['timestamp' => $now, 'ioping_home_ms' => 500])."\n");
        $link = $this->tmpDir.'/host-pressure-link.json';
        $this->pmssCreateSymlinkOrSkip($target, $link);
        $this->assertSame('normal', \pmssStorageHealthHostPressureStateRead($link, $now, $this->psiPath())['status']);
        $oversized = $this->pmssWriteFile($this->tmpDir.'/oversized.json', str_repeat(' ', 4097));
        $this->assertSame('normal', \pmssStorageHealthHostPressureStateRead($oversized, $now, $this->psiPath())['status']);
    }

    public function testHostPressureNoticeExplainsSharedServerCondition(): void
    {
        $html = \pmssStorageHealthHostPressureNoticeHtmlBuild([
            'status' => 'heavy',
            'psi_io_full_avg300' => 25.25,
            'ioping_home_ms' => 125.75,
        ]);
        $this->assertStringContainsAllStrings([
            'Shared storage is under heavy load',
            'server-wide I/O queue',
            'I/O wait: 25.3% of the last 5 minutes',
            'Storage response: 125.8 ms',
        ], $html);
        $this->assertSame('', \pmssStorageHealthHostPressureNoticeHtmlBuild([]));
        $row = \pmssStorageHealthStatusRowHtmlBuild([
            'status' => 'heavy', 'psi_io_full_avg300' => 25.25,
            'ioping_home_ms' => 125.75, 'ioping_age_min' => 2,
        ]);
        $this->assertStringContainsAllStrings(['Server storage', 'I/O wait: 25.3%', 'Storage response: 125.8 ms', 'measured 2 min ago'], $row);
        $this->assertStringNotContainsString('<script>', \pmssStorageHealthStatusRowHtmlBuild([
            'status' => 'normal', 'psi_io_full_avg300' => '<script>', 'ioping_home_ms' => '<script>',
        ]));
    }

    public function testCombinedNoticePrefersSpecificRaidActivity(): void
    {
        $now = 9000;
        $pressurePath = $this->pmssWriteFile($this->tmpDir.'/host-pressure-'.bin2hex(random_bytes(3)).'.json',
            json_encode(['timestamp' => $now, 'ioping_home_ms' => 500])."\n");
        $raidHtml = \pmssStorageHealthNoticeHtmlRead(
            $this->pmssWriteRelativeFile($this->tmpDir, 'mounts', "/dev/md1 /home ext4 rw 0 0\n", 0700),
            [['array' => 'md1', 'resync' => 'resync = 50.0% finish=10min speed=1000K/sec']],
            $pressurePath,
            $now,
            $this->psiPath()
        );
        $this->assertStringContainsAndOmitsStrings(['Home storage maintenance in progress'], ['Shared storage is under heavy load'], $raidHtml);

        $pressureHtml = \pmssStorageHealthNoticeHtmlRead(
            $this->pmssWriteRelativeFile($this->tmpDir, 'mounts', "/dev/vda1 /home ext4 rw 0 0\n", 0700),
            [],
            $pressurePath,
            $now,
            $this->psiPath()
        );
        $this->assertStringContainsString('Shared storage is under heavy load', $pressureHtml);
    }

    public function testInfoRendersRaidNoticeBeforeStandingStatus(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('etc/skel/www/info.php',
            ['echo $pmssStorageHealthNoticeHtml', 'echo $pmssStorageStatusHtml']);
    }

}
