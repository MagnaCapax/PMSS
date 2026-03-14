<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/watchdog.php';

class LighttpdSocketCleanupTest extends TestCase
{
    /**
     * @var string
     */
    private $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/pmss-lighttpd-socket-cleanup-'.bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0755, true);
        @mkdir($this->tempDir.'/.lighttpd', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
    }

    public function testReturnsZeroWhenNoSocketEntriesExist(): void
    {
        $this->assertEquals(0, \pmssLighttpdRemoveSocketEntries($this->tempDir.'/.lighttpd'));
    }

    public function testRemovesRegularSocketMarkerFile(): void
    {
        $markerPath = $this->tempDir.'/.lighttpd/php.socket-0';
        file_put_contents($markerPath, 'stale');

        $this->assertEquals(1, \pmssLighttpdRemoveSocketEntries($this->tempDir.'/.lighttpd'));
        $this->assertTrue(!file_exists($markerPath));
    }

    public function testRemovesSymlinkSocketEntry(): void
    {
        $targetPath = $this->tempDir.'/.lighttpd/socket-target';
        $linkPath = $this->tempDir.'/.lighttpd/php.socket-symlink';
        file_put_contents($targetPath, 'target');
        symlink($targetPath, $linkPath);

        $this->assertEquals(1, \pmssLighttpdRemoveSocketEntries($this->tempDir.'/.lighttpd'));
        $this->assertTrue(!is_link($linkPath));
        $this->assertTrue(file_exists($targetPath));
    }

    public function testRemovesUnixSocketEntry(): void
    {
        $socketPath = $this->tempDir.'/.lighttpd/php.socket-live';
        $server = @stream_socket_server('unix://'.$socketPath, $errno, $errstr);
        if ($server === false) {
            throw new SkipTest('Unix sockets unavailable: '.$errno.' '.$errstr);
        }

        try {
            $this->assertEquals(1, \pmssLighttpdRemoveSocketEntries($this->tempDir.'/.lighttpd'));
            $this->assertTrue(!file_exists($socketPath));
        } finally {
            fclose($server);
        }
    }

    public function testLeavesNonSocketFilesUntouched(): void
    {
        $keepPath = $this->tempDir.'/.lighttpd/not-a-socket';
        file_put_contents($keepPath, 'keep');

        $this->assertEquals(0, \pmssLighttpdRemoveSocketEntries($this->tempDir.'/.lighttpd'));
        $this->assertTrue(file_exists($keepPath));
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        foreach ((array) glob($path.'/*') as $childPath) {
            $this->removeTree($childPath);
        }

        @rmdir($path);
    }
}
