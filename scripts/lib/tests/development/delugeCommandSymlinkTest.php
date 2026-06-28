<?php
namespace PMSS\Tests;

require_once __DIR__.'/DelugeAppTestCase.php';

class DelugeCommandSymlinkTest extends DelugeAppTestCase
{
    protected function setUp(): void
    {
        $this->pmssSetUpDelugeFixture('pmss-deluge-command-link-');
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
        $systemPath = $this->makeExecutable('usr/bin/deluge-console');
        $localPath = $this->tempDir.'/usr/local/bin/deluge-console';
        $this->pmssWriteFile($localPath, "#!/bin/sh\necho legacy\n");
        @chmod($localPath, 0755);

        $result = \pmssEnsureDelugeCommandSymlink('deluge-console', $systemPath, $localPath, false, $this->logger);

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
        $this->pmssAssertMessagesContain($this->logs, 'Would replace legacy Deluge command path', 'Expected dry-run replace log');
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
        $this->pmssAssertMessagesContain($this->logs, 'missing system binary', 'Expected missing binary warning log');
    }

    public function testRejectsDirectoryAtLocalCommandPath(): void
    {
        $systemPath = $this->makeExecutable('usr/bin/deluge-web');
        $localPath = $this->tempDir.'/usr/local/bin/deluge-web';
        @mkdir($localPath, 0755, true);

        $result = \pmssEnsureDelugeCommandSymlink('deluge-web', $systemPath, $localPath, false, $this->logger);

        $this->assertTrue($result === false, 'Expected directory local path to be rejected');
        $this->assertTrue(is_dir($localPath), 'Expected directory path to remain untouched');
        $this->pmssAssertMessagesContain($this->logs, 'Refusing to replace Deluge command directory', 'Expected directory guard warning');
    }

    public function testCollectsOnlySafeLegacyPython2Artifacts(): void
    {
        $root = $this->tempDir.'/usr/local/lib/python2.7/dist-packages';
        $this->pmssWriteFile($root.'/deluge-2.0.3-py2.7.egg', 'legacy');
        $this->pmssWriteFile($root.'/deluge-2.0.5-py2.7.egg-info', 'legacy');
        $this->pmssWriteFile($root.'/not-deluge-2.0.5-py2.7.egg', 'other');

        $paths = \pmssDelugeLegacyPython2ArtifactPaths($root);

        $this->assertEquals([
            $root.'/deluge-2.0.3-py2.7.egg',
            $root.'/deluge-2.0.5-py2.7.egg-info',
        ], $paths);
    }

    public function testRemovesLegacyPython2ArtifactFiles(): void
    {
        $root = $this->tempDir.'/usr/local/lib/python2.7/dist-packages';
        $legacyPath = $this->pmssWriteFile($root.'/deluge-2.0.3-py2.7.egg', 'legacy');

        $removed = \pmssRemoveLegacyDelugePython2Artifacts(false, $this->logger, $root);

        $this->assertEquals(1, $removed, 'Expected one stale Deluge artifact to be removed');
        $this->assertTrue(!file_exists($legacyPath), 'Expected stale Deluge artifact file to be gone');
    }

    public function testDryRunKeepsLegacyPython2ArtifactFiles(): void
    {
        $root = $this->tempDir.'/usr/local/lib/python2.7/dist-packages';
        $legacyPath = $this->pmssWriteFile($root.'/deluge-2.0.5-py2.7.egg', 'legacy');

        $removed = \pmssRemoveLegacyDelugePython2Artifacts(true, $this->logger, $root);

        $this->assertEquals(1, $removed, 'Expected dry-run to report the removable artifact');
        $this->assertTrue(is_file($legacyPath), 'Expected dry-run to keep the legacy artifact file');
        $this->pmssAssertMessagesContain($this->logs, 'Would remove legacy Deluge Python 2 artifact', 'Expected dry-run cleanup log');
    }

    private function makeExecutable(string $relativePath): string
    {
        $path = $this->tempDir.'/'.$relativePath;
        $this->pmssWriteExecutableFile($path, "#!/bin/sh\nexit 0\n");
        return $path;
    }

}
