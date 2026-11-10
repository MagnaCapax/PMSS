<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/network/loopbackOwnerGuard.php';

/** Root-registry fixtures run only on the testing machine, inside temporary directories. */
class MonitoringRulesLoopbackRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new SkipTest('root-owned registry fixtures require root on the testing machine');
        }
        $this->tempDir = $this->pmssMakeTempDir('pmss-loopback-registry-');
    }

    private function readPorts(array $users, array &$messages): array
    {
        return \networkQbittorrentWebUiPortsRead($users, $this->tempDir, $this->pmssMakeArrayLogger($messages));
    }

    public function testRegistryReadsNativeAndSeparateDockerReservations(): void
    {
        $this->pmssWriteFiles($this->tempDir, ['qbittorrent-fixtur01' => "22001\n", 'media-stack-qbittorrent-fixtur01' => "32001\n"]);
        $messages = [];
        $this->assertSame(['fixtur01' => [22001, 32001]], $this->readPorts(['fixtur01' => 1001], $messages));
        $this->assertSame([], $messages);
    }

    public function testAbsentRegistryEntrySkipsOnlyThatAccountSilently(): void
    {
        // Accounts without qBittorrent have no reservation; the five-minute rebuild must not log for them.
        $this->pmssWriteFile($this->tempDir.'/qbittorrent-fixtur02', '22002');
        $messages = [];
        $this->assertSame(['fixtur02' => [22002]], $this->readPorts(['fixtur01' => 1001, 'fixtur02' => 1002], $messages));
        $this->assertSame([], $messages);
    }

    public function testInvalidRegistryPortsAreSkippedWithoutStoppingOtherAccounts(): void
    {
        foreach (['', '1023', '65536', '-22001', '22001junk', "22001\0", '1e4', str_repeat('9', 40)] as $raw) {
            $this->pmssWriteFiles($this->tempDir, ['qbittorrent-fixtur01' => $raw, 'qbittorrent-fixtur02' => '65535']);
            $messages = [];
            $this->assertSame(['fixtur02' => [65535]], $this->readPorts(['fixtur01' => 1001, 'fixtur02' => 1002], $messages));
            $this->assertSame(1, count($messages));
        }
        $this->pmssWriteFile($this->tempDir.'/qbittorrent-fixtur01', '1024');
        $messages = [];
        $this->assertSame(['fixtur01' => [1024]], $this->readPorts(['fixtur01' => '1001'], $messages));
    }

    public function testHomeDisplayPortIsIgnoredWithAndWithoutReservation(): void
    {
        $homeRoot = $this->pmssMakeTempDir('pmss-loopback-home-');
        $this->pmssTrackHomeRoot($homeRoot);
        $this->pmssWriteFiles($homeRoot, ['fixtur01/.qbittorrentPort' => '28001', 'fixtur02/.qbittorrentPort' => '28002']);
        $this->pmssWriteFile($this->tempDir.'/qbittorrent-fixtur01', '22001');
        $messages = [];
        $this->assertSame(['fixtur01' => [22001]], $this->readPorts(['fixtur01' => 1001, 'fixtur02' => 1002], $messages));
        $this->assertSame([], $messages);
    }

    public function testLinkedAndNonRegularRegistryEntriesAreRefused(): void
    {
        $target = $this->pmssMakeTempFile('pmss-loopback-port-');
        $this->pmssWriteFile($target, '22001');
        $this->pmssCreateSymlinkOrSkip($target, $this->tempDir.'/qbittorrent-fixtur01');
        $this->pmssEnsureDir($this->tempDir.'/qbittorrent-fixtur02');
        $messages = [];
        $this->assertSame([], $this->readPorts(['fixtur01' => 1001, 'fixtur02' => 1002], $messages));
        $this->assertSame(2, count($messages));
    }

    public function testWritableOrForeignOwnedRegistryFilesAreRefused(): void
    {
        $this->pmssWriteFiles($this->tempDir, ['qbittorrent-fixtur01' => '22001', 'qbittorrent-fixtur02' => '22002']);
        $this->assertTrue(chmod($this->tempDir.'/qbittorrent-fixtur01', 0666));
        $this->assertTrue(chown($this->tempDir.'/qbittorrent-fixtur02', 65534));
        $messages = [];
        $this->assertSame([], $this->readPorts(['fixtur01' => 1001, 'fixtur02' => 1002], $messages));
        $this->assertSame(2, count($messages));
    }

    public function testUnsafeRegistryDirectoryAndInvalidUidAreRefused(): void
    {
        $this->pmssWriteFile($this->tempDir.'/qbittorrent-fixtur01', '22001');
        $messages = [];
        $this->assertSame([], $this->readPorts(['fixtur01' => '1001;id'], $messages));
        $this->assertSame(1, count($messages));
        $this->assertStringContainsString('invalid uid', $messages[0]);
        $this->assertTrue(chmod($this->tempDir, 0777));
        $messages = [];
        $this->assertSame([], $this->readPorts(['fixtur01' => 1001], $messages));
        $this->assertSame(1, count($messages));
        $this->assertStringContainsString('registry directory', $messages[0]);
    }

    public function testInvalidDockerReservationKeepsNativeGuard(): void
    {
        $this->pmssWriteFiles($this->tempDir, ['qbittorrent-fixtur01' => '22001', 'media-stack-qbittorrent-fixtur01' => 'invalid']);
        $messages = [];
        $this->assertSame(['fixtur01' => [22001]], $this->readPorts(['fixtur01' => 1001], $messages));
        $this->assertSame(1, count($messages));
    }
}
