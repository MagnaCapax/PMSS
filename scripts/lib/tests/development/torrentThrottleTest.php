<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/traffic.php';

class TorrentThrottleTest extends TestCase
{
    private $homeRoot;
    private $user;

    public function setUp(): void
    {
        $this->homeRoot = sys_get_temp_dir().'/pmss-throttle-'.uniqid('', true);
        $this->user = 'alice';
        @mkdir($this->homeRoot, 0755, true);
        @mkdir($this->homeRoot.'/'.$this->user, 0755, true);
        putenv('PMSS_HOME_DIR='.$this->homeRoot);
    }

    public function tearDown(): void
    {
        $this->removeTree($this->homeRoot);
        putenv('PMSS_HOME_DIR');
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

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            if (is_dir($full) && !is_link($full)) {
                $this->removeTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
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
}
