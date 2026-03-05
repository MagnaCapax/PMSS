<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

putenv('PMSS_DELUGE_NO_ENTRYPOINT=1');
require_once dirname(__DIR__, 2).'/update/apps/deluge.php';

class DelugeCommandSymlinkTest extends TestCase
{
    /** @var string */
    private $tempDir;

    /** @var array<int,string> */
    private $logs = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/pmss-deluge-command-link-'.bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0700, true);
        $this->logs = [];
    }

    protected function tearDown(): void
    {
        $this->removePath($this->tempDir);
    }

    public function testCreatesSymlinkWhenLocalPathMissing(): void
    {
        $systemPath = $this->makeExecutable('usr/bin/deluged');
        $localPath = $this->tempDir.'/usr/local/bin/deluged';

        $result = \pmssEnsureDelugeCommandSymlink('deluged', $systemPath, $localPath, false, [$this, 'collectLog']);

        $this->assertTrue($result, 'Expected missing /usr/local command path to be created as symlink');
        $this->assertTrue(is_link($localPath), 'Expected local command path to be a symlink');
        $this->assertEquals($systemPath, readlink($localPath));
    }

    public function testReplacesLegacyBinaryWithSymlink(): void
    {
        $systemPath = $this->makeExecutable('usr/bin/deluge-web');
        $localPath = $this->tempDir.'/usr/local/bin/deluge-web';
        @mkdir(dirname($localPath), 0755, true);
        file_put_contents($localPath, "#!/bin/sh\necho legacy\n");
        @chmod($localPath, 0755);

        $result = \pmssEnsureDelugeCommandSymlink('deluge-web', $systemPath, $localPath, false, [$this, 'collectLog']);

        $this->assertTrue($result, 'Expected legacy /usr/local binary to be replaced');
        $this->assertTrue(is_link($localPath), 'Expected legacy binary path to become a symlink');
        $this->assertEquals($systemPath, readlink($localPath));
    }

    public function testDryRunLeavesLegacyBinaryUntouched(): void
    {
        $systemPath = $this->makeExecutable('usr/bin/deluged');
        $localPath = $this->tempDir.'/usr/local/bin/deluged';
        @mkdir(dirname($localPath), 0755, true);
        $legacy = "#!/bin/sh\necho legacy\n";
        file_put_contents($localPath, $legacy);
        @chmod($localPath, 0755);

        $result = \pmssEnsureDelugeCommandSymlink('deluged', $systemPath, $localPath, true, [$this, 'collectLog']);

        $this->assertTrue($result, 'Expected dry-run to report success for replaceable legacy path');
        $this->assertTrue(is_file($localPath), 'Expected dry-run to keep legacy file in place');
        $this->assertTrue(!is_link($localPath), 'Expected dry-run to avoid creating symlink');
        $this->assertEquals($legacy, (string) file_get_contents($localPath));
        $this->assertTrue($this->logContains('Would replace legacy Deluge command path'), 'Expected dry-run replace log');
    }

    public function testReturnsTrueWhenCorrectSymlinkAlreadyExists(): void
    {
        $systemPath = $this->makeExecutable('usr/bin/deluged');
        $localPath = $this->tempDir.'/usr/local/bin/deluged';
        @mkdir(dirname($localPath), 0755, true);
        @symlink($systemPath, $localPath);

        $result = \pmssEnsureDelugeCommandSymlink('deluged', $systemPath, $localPath, false, [$this, 'collectLog']);

        $this->assertTrue($result, 'Expected already-correct symlink to remain valid');
        $this->assertTrue(is_link($localPath), 'Expected local command path to remain a symlink');
        $this->assertEquals($systemPath, readlink($localPath));
    }

    public function testReturnsFalseWhenSystemBinaryMissing(): void
    {
        $systemPath = $this->tempDir.'/usr/bin/deluged';
        $localPath = $this->tempDir.'/usr/local/bin/deluged';

        $result = \pmssEnsureDelugeCommandSymlink('deluged', $systemPath, $localPath, false, [$this, 'collectLog']);

        $this->assertTrue($result === false, 'Expected missing system binary to fail refresh');
        $this->assertTrue($this->logContains('missing system binary'), 'Expected missing binary warning log');
    }

    public function testRejectsDirectoryAtLocalCommandPath(): void
    {
        $systemPath = $this->makeExecutable('usr/bin/deluge-web');
        $localPath = $this->tempDir.'/usr/local/bin/deluge-web';
        @mkdir($localPath, 0755, true);

        $result = \pmssEnsureDelugeCommandSymlink('deluge-web', $systemPath, $localPath, false, [$this, 'collectLog']);

        $this->assertTrue($result === false, 'Expected directory local path to be rejected');
        $this->assertTrue(is_dir($localPath), 'Expected directory path to remain untouched');
        $this->assertTrue($this->logContains('Refusing to replace Deluge command directory'), 'Expected directory guard warning');
    }

    public function collectLog(string $message): void
    {
        $this->logs[] = $message;
    }

    private function makeExecutable(string $relativePath): string
    {
        $path = $this->tempDir.'/'.$relativePath;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        @chmod($path, 0755);
        return $path;
    }

    private function logContains(string $needle): bool
    {
        foreach ($this->logs as $message) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function removePath(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }

        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            @rmdir($path);
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removePath($path.'/'.$item);
        }

        @rmdir($path);
    }
}
