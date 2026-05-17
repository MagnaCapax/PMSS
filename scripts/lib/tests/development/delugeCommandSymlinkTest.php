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

    /** @var callable */
    private $logger;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirArrayLogger('tempDir', 'pmss-deluge-command-link-', $this->logs, $this->logger, 0700);
    }

    public function testCreatesSymlinkWhenLocalPathMissing(): void
    {
        $systemPath = $this->makeExecutable('usr/bin/deluged');
        $localPath = $this->tempDir.'/usr/local/bin/deluged';

        $result = \pmssEnsureDelugeCommandSymlink('deluged', $systemPath, $localPath, false, $this->logger);

        $this->assertTrue($result, 'Expected missing /usr/local command path to be created as symlink');
        $this->assertTrue(is_link($localPath), 'Expected local command path to be a symlink');
        $this->assertEquals($systemPath, readlink($localPath));
    }

    public function testReplacesLegacyBinaryWithSymlink(): void
    {
        $systemPath = $this->makeExecutable('usr/bin/deluge-web');
        $localPath = $this->tempDir.'/usr/local/bin/deluge-web';
        $this->pmssWriteFile($localPath, "#!/bin/sh\necho legacy\n");
        @chmod($localPath, 0755);

        $result = \pmssEnsureDelugeCommandSymlink('deluge-web', $systemPath, $localPath, false, $this->logger);

        $this->assertTrue($result, 'Expected legacy /usr/local binary to be replaced');
        $this->assertTrue(is_link($localPath), 'Expected legacy binary path to become a symlink');
        $this->assertEquals($systemPath, readlink($localPath));
    }

    public function testDryRunLeavesLegacyBinaryUntouched(): void
    {
        $systemPath = $this->makeExecutable('usr/bin/deluged');
        $localPath = $this->tempDir.'/usr/local/bin/deluged';
        $legacy = "#!/bin/sh\necho legacy\n";
        $this->pmssWriteFile($localPath, $legacy);
        @chmod($localPath, 0755);

        $result = \pmssEnsureDelugeCommandSymlink('deluged', $systemPath, $localPath, true, $this->logger);

        $this->assertTrue($result, 'Expected dry-run to report success for replaceable legacy path');
        $this->assertTrue(is_file($localPath), 'Expected dry-run to keep legacy file in place');
        $this->assertTrue(!is_link($localPath), 'Expected dry-run to avoid creating symlink');
        $this->assertEquals($legacy, (string) file_get_contents($localPath));
        $this->assertTrue($this->pmssLogBufferContains($this->logs, 'Would replace legacy Deluge command path'), 'Expected dry-run replace log');
    }

    public function testReturnsTrueWhenCorrectSymlinkAlreadyExists(): void
    {
        $systemPath = $this->makeExecutable('usr/bin/deluged');
        $localPath = $this->tempDir.'/usr/local/bin/deluged';
        @mkdir(dirname($localPath), 0755, true);
        @symlink($systemPath, $localPath);

        $result = \pmssEnsureDelugeCommandSymlink('deluged', $systemPath, $localPath, false, $this->logger);

        $this->assertTrue($result, 'Expected already-correct symlink to remain valid');
        $this->assertTrue(is_link($localPath), 'Expected local command path to remain a symlink');
        $this->assertEquals($systemPath, readlink($localPath));
    }

    public function testReturnsFalseWhenSystemBinaryMissing(): void
    {
        $systemPath = $this->tempDir.'/usr/bin/deluged';
        $localPath = $this->tempDir.'/usr/local/bin/deluged';

        $result = \pmssEnsureDelugeCommandSymlink('deluged', $systemPath, $localPath, false, $this->logger);

        $this->assertTrue($result === false, 'Expected missing system binary to fail refresh');
        $this->assertTrue($this->pmssLogBufferContains($this->logs, 'missing system binary'), 'Expected missing binary warning log');
    }

    public function testRejectsDirectoryAtLocalCommandPath(): void
    {
        $systemPath = $this->makeExecutable('usr/bin/deluge-web');
        $localPath = $this->tempDir.'/usr/local/bin/deluge-web';
        @mkdir($localPath, 0755, true);

        $result = \pmssEnsureDelugeCommandSymlink('deluge-web', $systemPath, $localPath, false, $this->logger);

        $this->assertTrue($result === false, 'Expected directory local path to be rejected');
        $this->assertTrue(is_dir($localPath), 'Expected directory path to remain untouched');
        $this->assertTrue($this->pmssLogBufferContains($this->logs, 'Refusing to replace Deluge command directory'), 'Expected directory guard warning');
    }

    private function makeExecutable(string $relativePath): string
    {
        $path = $this->tempDir.'/'.$relativePath;
        $this->pmssWriteFile($path, "#!/bin/sh\nexit 0\n");
        @chmod($path, 0755);
        return $path;
    }

}
