<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/storageHealth.php';

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

    private function assertHomeRaidActivity(array $raidEntries, array $expected): void
    {
        $activity = \pmssStorageHealthHomeRaidActivity($this->homeMountsPath('/dev/md1'), $raidEntries);

        $this->assertTrue(is_array($activity), 'Expected activity for /home array');
        foreach ($expected as $key => $value) {
            $this->assertEquals($value, $activity[$key]);
        }
    }

    public function testParsesRaidActivitySummaryDetails(): void
    {
        $summary = \pmssStorageHealthRaidActivitySummaryParse(
            '[==>..................]  check = 12.3% (120/999) finish=15.4min speed=123456K/sec'
        );

        $this->assertEquals('check', $summary['operation']);
        $this->assertEquals('12.3%', $summary['progress']);
        $this->assertEquals('15.4min', $summary['eta']);
        $this->assertEquals('123456K/sec', $summary['speed']);
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
            $this->assertHomeRaidActivity($raidEntries, $expected);
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

    public function testPerformanceStatusUsesCheckOperationInReason(): void
    {
        $status = \pmssStorageHealthPerformanceStatus([
            ['array' => 'md2', 'severity' => 'warn', 'flags' => ['rebuild_in_progress'], 'operation' => 'check'],
        ]);

        $this->assertTrue(is_array($status), 'Expected performance status for RAID check');
        $this->assertStringContainsString('check', $status['reason']);
    }
}
