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
        return \networkQbittorrentWebUiPortsRead($users, $this->tempDir, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
    }

    public function testRegistryReadsNativeAndSeparateDockerReservations(): void
    {
        $this->pmssWriteFiles($this->tempDir, ['qbittorrent-alice' => "22001\n", 'media-stack-qbittorrent-alice' => "32001\n"]);
        $messages = [];
        $this->assertSame(['alice' => [22001, 32001]], $this->readPorts(['alice' => 1001], $messages));
        $this->assertSame([], $messages);
    }

    public function testAbsentRegistryEntrySkipsOnlyThatAccountSilently(): void
    {
        // Accounts without qBittorrent have no reservation; the five-minute rebuild must not log for them.
        $this->pmssWriteFile($this->tempDir.'/qbittorrent-bob', '22002');
        $messages = [];
        $this->assertSame(['bob' => [22002]], $this->readPorts(['alice' => 1001, 'bob' => 1002], $messages));
        $this->assertSame([], $messages);
    }

    public function testInvalidRegistryPortsAreSkippedWithoutStoppingOtherAccounts(): void
    {
        foreach (['', '1023', '65536', '-22001', '22001junk', "22001\0", '1e4', str_repeat('9', 40)] as $raw) {
            $this->pmssWriteFiles($this->tempDir, ['qbittorrent-alice' => $raw, 'qbittorrent-bob' => '65535']);
            $messages = [];
            $this->assertSame(['bob' => [65535]], $this->readPorts(['alice' => 1001, 'bob' => 1002], $messages));
            $this->assertSame(1, count($messages));
        }
        $this->pmssWriteFile($this->tempDir.'/qbittorrent-alice', '1024');
        $messages = [];
        $this->assertSame(['alice' => [1024]], $this->readPorts(['alice' => '1001'], $messages));
    }

    public function testHomeDisplayPortIsIgnoredWithAndWithoutReservation(): void
    {
        $homeRoot = $this->pmssMakeTempDir('pmss-loopback-home-');
        $this->pmssTrackHomeRoot($homeRoot);
        $this->pmssWriteFiles($homeRoot, ['alice/.qbittorrentPort' => '28001', 'bob/.qbittorrentPort' => '28002']);
        $this->pmssWriteFile($this->tempDir.'/qbittorrent-alice', '22001');
        $messages = [];
        $this->assertSame(['alice' => [22001]], $this->readPorts(['alice' => 1001, 'bob' => 1002], $messages));
        $this->assertSame([], $messages);
    }

    public function testLinkedAndNonRegularRegistryEntriesAreRefused(): void
    {
        $target = $this->pmssMakeTempFile('pmss-loopback-port-');
        $this->pmssWriteFile($target, '22001');
        $this->pmssCreateSymlinkOrSkip($target, $this->tempDir.'/qbittorrent-alice');
        $this->pmssEnsureDir($this->tempDir.'/qbittorrent-bob');
        $messages = [];
        $this->assertSame([], $this->readPorts(['alice' => 1001, 'bob' => 1002], $messages));
        $this->assertSame(2, count($messages));
    }

    public function testWritableOrForeignOwnedRegistryFilesAreRefused(): void
    {
        $this->pmssWriteFiles($this->tempDir, ['qbittorrent-alice' => '22001', 'qbittorrent-bob' => '22002']);
        $this->assertTrue(chmod($this->tempDir.'/qbittorrent-alice', 0666));
        $this->assertTrue(chown($this->tempDir.'/qbittorrent-bob', 65534));
        $messages = [];
        $this->assertSame([], $this->readPorts(['alice' => 1001, 'bob' => 1002], $messages));
        $this->assertSame(2, count($messages));
    }

    public function testUnsafeRegistryDirectoryAndInvalidUidAreRefused(): void
    {
        $this->pmssWriteFile($this->tempDir.'/qbittorrent-alice', '22001');
        $messages = [];
        $this->assertSame([], $this->readPorts(['alice' => '1001;id'], $messages));
        $this->assertSame(1, count($messages));
        $this->assertStringContainsString('invalid uid', $messages[0]);
        $this->assertTrue(chmod($this->tempDir, 0777));
        $messages = [];
        $this->assertSame([], $this->readPorts(['alice' => 1001], $messages));
        $this->assertSame(1, count($messages));
        $this->assertStringContainsString('registry directory', $messages[0]);
    }

    public function testInvalidDockerReservationKeepsNativeGuard(): void
    {
        $this->pmssWriteFiles($this->tempDir, ['qbittorrent-alice' => '22001', 'media-stack-qbittorrent-alice' => 'invalid']);
        $messages = [];
        $this->assertSame(['alice' => [22001]], $this->readPorts(['alice' => 1001], $messages));
        $this->assertSame(1, count($messages));
    }
}
