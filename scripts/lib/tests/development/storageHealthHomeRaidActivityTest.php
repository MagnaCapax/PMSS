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

    private function homeMountsPath(string $device): string
    {
        return $this->pmssWriteRelativeFile($this->tmpDir, 'mounts', $device." /home ext4 rw 0 0\n", 0700);
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
        $homeArray = \pmssStorageHealthHomeArrayResolve($this->homeMountsPath('/dev/md0'));
        $this->assertEquals('md0', $homeArray);
    }

    public function testResolvesHomeArrayFromNamedMdDevice(): void
    {
        @mkdir($this->tmpDir.'/dev/md', 0700, true);
        $this->pmssWriteRelativeFile($this->tmpDir, 'dev/md7', '', 0700);
        symlink('../md7', $this->tmpDir.'/dev/md/home');

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
            $activity = \pmssStorageHealthHomeRaidActivity($this->homeMountsPath('/dev/md1'), $raidEntries);
            $this->assertTrue(is_array($activity), 'Expected activity for /home array');
            $this->pmssAssertArraySubsetSame($expected, $activity);
        }
    }

    public function testHomeRaidActivityReturnsNullWhenHomeIsNotOnMd(): void
    {
        $activity = \pmssStorageHealthHomeRaidActivity($this->homeMountsPath('/dev/vda1'), [
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

    private function hostPressurePath(array $payload): string
    {
        return $this->pmssWriteFile(
            $this->tmpDir.'/host-pressure-'.bin2hex(random_bytes(3)).'.json',
            json_encode($payload)."\n"
        );
    }

    public function testHostPressureStateUsesConservativeThresholds(): void
    {
        $now = 2000;
        $healthy = $this->hostPressurePath([
            'timestamp' => $now,
            'psi_io_full_avg300' => 19.9,
            'ioping_home_ms' => 100.0,
        ]);
        $this->assertSame(null, \pmssStorageHealthHostPressureStateRead($healthy, $now));

        foreach ([
            ['field' => 'psi_io_full_avg300', 'value' => 20.0],
            ['field' => 'ioping_home_ms', 'value' => 100.1],
        ] as $case) {
            $path = $this->hostPressurePath(['timestamp' => $now, $case['field'] => $case['value']]);
            $state = \pmssStorageHealthHostPressureStateRead($path, $now);
            $this->assertTrue(is_array($state), 'Expected threshold boundary to alert');
            $this->assertTrue(isset($state[$case['field']]), 'Expected matching pressure signal');
        }
    }

    public function testHostPressureStateRejectsStaleMalformedAndUnsafeSnapshots(): void
    {
        $now = 5000;
        foreach ([
            $this->hostPressurePath(['timestamp' => $now - 901, 'ioping_home_ms' => 500]),
            $this->hostPressurePath(['timestamp' => $now + 1, 'ioping_home_ms' => 500]),
            $this->pmssWriteFile($this->tmpDir.'/malformed.json', '{broken'),
        ] as $path) {
            $this->assertSame(null, \pmssStorageHealthHostPressureStateRead($path, $now));
        }

        $target = $this->hostPressurePath(['timestamp' => $now, 'ioping_home_ms' => 500]);
        $link = $this->tmpDir.'/host-pressure-link.json';
        $this->pmssCreateSymlinkOrSkip($target, $link);
        $this->assertSame(null, \pmssStorageHealthHostPressureStateRead($link, $now));
    }

    public function testHostPressureNoticeExplainsSharedServerCondition(): void
    {
        $html = \pmssStorageHealthHostPressureNoticeHtmlBuild([
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
    }

    public function testCombinedNoticePrefersSpecificRaidActivity(): void
    {
        $now = 9000;
        $pressurePath = $this->hostPressurePath(['timestamp' => $now, 'ioping_home_ms' => 500]);
        $raidHtml = \pmssStorageHealthNoticeHtmlRead(
            $this->homeMountsPath('/dev/md1'),
            [['array' => 'md1', 'resync' => 'resync = 50.0% finish=10min speed=1000K/sec']],
            $pressurePath,
            $now
        );
        $this->assertStringContainsString('Home storage maintenance in progress', $raidHtml);
        $this->assertStringNotContainsString('Shared storage is under heavy load', $raidHtml);

        $pressureHtml = \pmssStorageHealthNoticeHtmlRead(
            $this->homeMountsPath('/dev/vda1'),
            [],
            $pressurePath,
            $now
        );
        $this->assertStringContainsString('Shared storage is under heavy load', $pressureHtml);
    }

}
