<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/storageHealth.php';

class StorageHealthHomeRaidActivityTest extends TestCase
{
    /** @var string */
    private $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/pmss-storage-health-home-'.bin2hex(random_bytes(4));
        @mkdir($this->tmpDir.'/sys/class/block', 0700, true);
        @mkdir($this->tmpDir.'/dev/mapper', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }

        $entries = @scandir($path);
        if (!is_array($entries)) {
            @rmdir($path);
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path.'/'.$entry);
        }

        @rmdir($path);
    }

    private function writeFile(string $relativePath, string $content): string
    {
        $path = $this->tmpDir.'/'.$relativePath;
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, $content);
        return $path;
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
        $mountsPath = $this->writeFile('mounts', "/dev/md0 /home ext4 rw 0 0\n");

        $homeArray = \pmssStorageHealthHomeArrayResolve($mountsPath);
        $this->assertEquals('md0', $homeArray);
    }

    public function testResolvesHomeArrayFromNamedMdDevice(): void
    {
        @mkdir($this->tmpDir.'/dev/md', 0700, true);
        $this->writeFile('dev/md7', '');
        symlink('../md7', $this->tmpDir.'/dev/md/home');

        $mountsPath = $this->writeFile('mounts', $this->tmpDir.'/dev/md/home /home ext4 rw 0 0'."\n");

        $homeArray = \pmssStorageHealthHomeArrayResolve($mountsPath);
        $this->assertEquals('md7', $homeArray);
    }

    public function testHomeRaidActivityReturnsOnlyHomeArrayWork(): void
    {
        $mountsPath = $this->writeFile('mounts', "/dev/md1 /home ext4 rw 0 0\n");
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
        $mountsPath = $this->writeFile('mounts', "/dev/vda1 /home ext4 rw 0 0\n");
        $raidEntries = [
            ['array' => 'md1', 'resync' => '      [>....................]  resync = 5.1% finish=90.0min speed=1000K/sec'],
        ];

        $activity = \pmssStorageHealthHomeRaidActivity($mountsPath, $raidEntries);
        $this->assertTrue($activity === null, 'Expected no activity when /home is not backed by md');
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

    public function testPerformanceStatusUsesCheckOperationInReason(): void
    {
        $status = \pmssStorageHealthPerformanceStatus([
            ['array' => 'md2', 'severity' => 'warn', 'flags' => ['rebuild_in_progress'], 'operation' => 'check'],
        ]);

        $this->assertTrue(is_array($status), 'Expected performance status for RAID check');
        $this->assertStringContainsString('check', $status['reason']);
    }
}
