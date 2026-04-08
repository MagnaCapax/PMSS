<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/traffic.php';

class TorrentThrottleTest extends TestCase
{
    private $homeRoot;
    private $user;

    public function setUp(): void
    {
        $this->homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-throttle-');
        $this->user = 'alice';
        @mkdir($this->homeRoot.'/'.$this->user, 0755, true);
    }

    private function throttlePath(): string
    {
        return $this->homeRoot.'/'.$this->user.'/.torrentThrottle';
    }

    private function writeThrottleFile(string $content, int $mode = 0640): void
    {
        $path = $this->throttlePath();
        file_put_contents($path, $content);
        chmod($path, $mode);
    }

    public function testReadReturnsNullWhenMissing(): void
    {
        $this->assertEquals(null, pmssReadTorrentThrottle($this->user));
    }

    public function testReadReturnsNullForInvalidContent(): void
    {
        $this->writeThrottleFile('nope');
        $this->assertEquals(null, pmssReadTorrentThrottle($this->user));
    }

    public function testReadReturnsZeroForZeroValue(): void
    {
        $this->writeThrottleFile('0');
        $this->assertEquals(0, pmssReadTorrentThrottle($this->user));
    }

    public function testReadReturnsValueForPositive(): void
    {
        $this->writeThrottleFile('123');
        $this->assertEquals(123, pmssReadTorrentThrottle($this->user));
    }

    public function testReadRejectsGroupWritable(): void
    {
        $this->writeThrottleFile('10', 0666);
        $this->assertEquals(null, pmssReadTorrentThrottle($this->user));
    }

    public function testReadRejectsInvalidUsernameBeforePathResolution(): void
    {
        @mkdir($this->homeRoot.'/alice/evil', 0755, true);
        file_put_contents($this->homeRoot.'/alice/evil/.torrentThrottle', '77');

        $this->assertEquals(null, pmssReadTorrentThrottle('alice/evil'));
    }

    public function testWriteCreatesFileForPositive(): void
    {
        $result = pmssWriteTorrentThrottle($this->user, 55);
        $this->assertTrue($result, 'Expected write to succeed');
        $this->assertTrue(is_file($this->throttlePath()), 'Throttle file not created');
        $this->assertEquals('55', trim((string) file_get_contents($this->throttlePath())));
    }

    public function testWriteRemovesFileForZero(): void
    {
        $this->writeThrottleFile('25');
        $result = pmssWriteTorrentThrottle($this->user, 0);
        $this->assertTrue($result, 'Expected removal to succeed');
        $this->assertTrue(!is_file($this->throttlePath()), 'Throttle file was not removed');
    }

    public function testWriteRejectsMissingHome(): void
    {
        $this->assertTrue(!pmssWriteTorrentThrottle('missing', 10), 'Expected write to fail without home dir');
    }

    public function testWriteRejectsSymlink(): void
    {
        $path = $this->throttlePath();
        file_put_contents($this->homeRoot.'/'.$this->user.'/target', '1');
        symlink($this->homeRoot.'/'.$this->user.'/target', $path);
        $this->assertTrue(!pmssWriteTorrentThrottle($this->user, 10), 'Expected write to fail on symlink');
    }

    public function testWriteRejectsDirectoryWhenRemovingThrottle(): void
    {
        $path = $this->throttlePath();
        @mkdir($path, 0755, true);

        $this->assertTrue(!pmssWriteTorrentThrottle($this->user, 0), 'Expected removal to fail on directory');
        $this->assertTrue(is_dir($path), 'Throttle directory should remain untouched');
    }

    public function testWriteRejectsDirectoryWhenWritingThrottle(): void
    {
        $path = $this->throttlePath();
        @mkdir($path, 0755, true);

        $this->assertTrue(!pmssWriteTorrentThrottle($this->user, 10), 'Expected write to fail on directory');
        $this->assertTrue(is_dir($path), 'Throttle directory should remain untouched');
    }

    public function testWriteRejectsInvalidUsernameBeforePathResolution(): void
    {
        @mkdir($this->homeRoot.'/alice/evil', 0755, true);

        $this->assertTrue(!pmssWriteTorrentThrottle('alice/evil', 10), 'Expected invalid username write to fail');
        $this->assertTrue(!is_file($this->homeRoot.'/alice/evil/.torrentThrottle'), 'Traversal-like path should remain untouched');
    }
}
