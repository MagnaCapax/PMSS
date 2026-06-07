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
        $mountsPath = $this->pmssWriteRelativeFile($this->tmpDir, 'mounts', "/dev/md0 /home ext4 rw 0 0\n", 0700);

        $homeArray = \pmssStorageHealthHomeArrayResolve($mountsPath);
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

    public function testHomeRaidActivityReturnsOnlyHomeArrayWork(): void
    {
        $mountsPath = $this->pmssWriteRelativeFile($this->tmpDir, 'mounts', "/dev/md1 /home ext4 rw 0 0\n", 0700);
        $raidEntries = [
            ['array' => 'md0', 'resync' => '      [>....................]  resync = 5.1% finish=90.0min speed=1000K/sec'],
            ['array' => 'md1', 'resync' => '      [>....................]  recovery = 7.5% finish=60.0min speed=2000K/sec'],
        ];

        $activity = \pmssStorageHealthHomeRaidActivity($mountsPath, $raidEntries);

        $this->assertTrue(is_array($activity), 'Expected activity for /home array');
        $this->assertEquals('md1', $activity['array']);
        $this->assertEquals('recovery', $activity['operation']);
        $this->assertEquals('7.5%', $activity['progress']);
    }

    public function testHomeRaidActivityReturnsNullWhenHomeIsNotOnMd(): void
    {
        $mountsPath = $this->pmssWriteRelativeFile($this->tmpDir, 'mounts', "/dev/vda1 /home ext4 rw 0 0\n", 0700);
        $raidEntries = [
            ['array' => 'md1', 'resync' => '      [>....................]  resync = 5.1% finish=90.0min speed=1000K/sec'],
        ];

        $activity = \pmssStorageHealthHomeRaidActivity($mountsPath, $raidEntries);
        $this->assertTrue($activity === null, 'Expected no activity when /home is not backed by md');
    }

    public function testHomeRaidActivityReturnsDegradedNoticeWithoutRebuild(): void
    {
        $mountsPath = $this->pmssWriteRelativeFile($this->tmpDir, 'mounts', "/dev/md1 /home ext4 rw 0 0\n", 0700);
        $raidEntries = [
            ['array' => 'md1', 'flags' => ['degraded'], 'severity' => 'fail'],
        ];

        $activity = \pmssStorageHealthHomeRaidActivity($mountsPath, $raidEntries);

        $this->assertTrue(is_array($activity), 'Expected degraded notice for /home array');
        $this->assertEquals('md1', $activity['array']);
        $this->assertEquals(['degraded'], $activity['flags']);
    }

    public function testHomeRaidActivityPrefersActiveRebuildOverDegradedNotice(): void
    {
        $mountsPath = $this->pmssWriteRelativeFile($this->tmpDir, 'mounts', "/dev/md1 /home ext4 rw 0 0\n", 0700);
        $raidEntries = [
            [
                'array' => 'md1',
                'flags' => ['degraded', 'rebuild_in_progress'],
                'severity' => 'fail',
                'resync' => '      [>....................]  recovery = 7.5% finish=60.0min speed=2000K/sec',
            ],
        ];

        $activity = \pmssStorageHealthHomeRaidActivity($mountsPath, $raidEntries);

        $this->assertTrue(is_array($activity), 'Expected active rebuild for /home array');
        $this->assertEquals('recovery', $activity['operation']);
        $this->assertEquals('7.5%', $activity['progress']);
    }

    public function testHomeRaidNoticeHtmlIncludesAvailableDetails(): void
    {
        $html = \pmssStorageHealthHomeRaidNoticeHtmlBuild([
            'array' => 'md0',
            'operation' => 'check',
            'progress' => '12.3%',
            'eta' => '15.4min',
            'speed' => '123456K/sec',
        ]);

        $this->assertStringContainsString('Home storage maintenance in progress', $html);
        $this->assertStringContainsString('Progress: 12.3%', $html);
        $this->assertStringContainsString('ETA: 15.4min', $html);
    }

    public function testHomeRaidNoticeHtmlOmitsMetaWhenNoDetailsProvided(): void
    {
        $html = \pmssStorageHealthHomeRaidNoticeHtmlBuild([
            'array' => 'md0',
            'operation' => 'check',
        ]);

        $this->assertStringContainsString('Home storage maintenance in progress', $html);
        $this->assertStringContainsString('RAID array md0 is running a check', $html);
        $this->assertTrue(strpos($html, 'pmss-raid-meta') === false, 'Meta details should be omitted when no values exist');
    }

    public function testHomeRaidNoticeHtmlBuildsDegradedAlert(): void
    {
        $html = \pmssStorageHealthHomeRaidNoticeHtmlBuild([
            'array' => 'md1',
            'flags' => ['degraded'],
        ]);

        $this->assertStringContainsString('Storage array degraded', $html);
        $this->assertStringContainsString('without full redundancy', $html);
        $this->assertStringContainsString('pmss-raid-notice-error', $html);
        $this->assertTrue(strpos($html, 'Progress:') === false, 'Degraded notice should not show rebuild metadata');
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
