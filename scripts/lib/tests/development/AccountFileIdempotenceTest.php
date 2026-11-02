<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

/** Account writer convergence must preserve both watched file and directory mtimes. */
class AccountFileIdempotenceTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-account-idempotence-']; }

    public function testIdenticalBytesPreserveInodeAndBothMtimes(): void
    {
        $owner = $this->pmssCurrentOwner();
        foreach (['', "config\n", str_repeat("binary\0\xff\n", 16384)] as $index => $content) {
            $home = $this->pmssEnsureDir($this->tempDir.'/fixture'.$index);
            $path = $home.'/config';
            $this->assertTrue(\pmssReplaceAccountFile($owner, $home, $path, $content, 0640));
            touch($path, 100);
            touch($home, 110);
            clearstatcache();
            $before = lstat($path);

            $this->assertTrue(\pmssReplaceAccountFile($owner, $home, $path, $content, 0640));
            clearstatcache();
            $this->assertSame($before['ino'], fileinode($path));
            $this->assertSame(100, filemtime($path));
            $this->assertSame(110, filemtime($home));
            $this->assertSame($content, file_get_contents($path));
            $this->assertSame([], glob($home.'/config.pmss-tmp-*'));
        }
    }

    public function testChangedBytesStillReplaceAtomically(): void
    {
        $owner = $this->pmssCurrentOwner();
        $path = $this->tempDir.'/config';
        $this->assertTrue(\pmssReplaceAccountFile($owner, $this->tempDir, $path, "first\n", 0640));
        touch($path, 100);
        clearstatcache();
        $before = lstat($path);

        // Equal length must still compare bytes; a removed newline also changes content.
        foreach (["other\n", 'other', ''] as $content) {
            $this->assertTrue(\pmssReplaceAccountFile($owner, $this->tempDir, $path, $content, 0640));
            clearstatcache();
            $this->assertTrue($before['ino'] !== fileinode($path));
            $this->assertTrue(filemtime($path) > 100);
            $this->assertSame($content, file_get_contents($path));
            $before = lstat($path);
        }
        $this->assertSame([], glob($this->tempDir.'/config.pmss-tmp-*'));
    }

    public function testIdenticalBytesStillRepairMode(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/config', 'kept');
        chmod($path, 0666);
        touch($path, 100);
        $this->assertTrue(\pmssReplaceAccountFile($this->pmssCurrentOwner(), $this->tempDir, $path, 'kept', 0640));
        clearstatcache();
        $this->assertSame(0640, fileperms($path) & 07777);
        $this->assertSame('kept', file_get_contents($path));
        $this->assertTrue(filemtime($path) > 100);
    }

    public function testIdenticalBytesCannotBypassInvalidModeOrAccount(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/config', 'kept');
        chmod($path, 0640);
        touch($path, 100);
        $this->assertFalse(\pmssReplaceAccountFile($this->pmssCurrentOwner(), $this->tempDir, $path, 'kept', 01000));
        $this->assertFalse(\pmssReplaceAccountFile('fixtur-missing-99', $this->tempDir, $path, 'kept', 0640));
        clearstatcache();
        $this->assertSame(100, filemtime($path));
        $this->assertSame(0640, fileperms($path) & 07777);
    }

    public function testIdenticalBytesCannotBypassLinkedTargetChecks(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/original', 'kept');
        chmod($path, 0640);
        $this->pmssCreateSymlinkOrSkip($path, $this->tempDir.'/symlink');
        $this->assertTrue(link($path, $this->tempDir.'/hardlink'));
        foreach (['symlink', 'hardlink'] as $name) {
            $this->assertFalse(\pmssReplaceAccountFile(
                $this->pmssCurrentOwner(), $this->tempDir, $this->tempDir.'/'.$name, 'kept', 0640
            ));
        }
        $this->assertSame('kept', file_get_contents($path));
    }

    public function testUnchangedFragmentDoesNotTriggerFreshnessRestart(): void
    {
        $directory = $this->pmssEnsureDir($this->tempDir.'/.lighttpd/custom.d');
        $config = $this->pmssWriteFile($this->tempDir.'/.lighttpd.conf', 'main');
        $path = $directory.'/pmss-rclone.conf';
        $fragment = \pmssLighttpdManagedProxyFragment('rclone', 'fixtur01', 4001);
        $owner = $this->pmssCurrentOwner();
        $this->assertTrue(\pmssReplaceAccountFile($owner, $this->tempDir, $path, $fragment, 0640));
        // Deterministic process/config times avoid sleeps and filesystem clock resolution.
        foreach ([$config, $path, $directory] as $entry) touch($entry, 100);
        $this->assertTrue(\pmssReplaceAccountFile($owner, $this->tempDir, $path, $fragment, 0640));
        clearstatcache();
        $this->assertFalse(\pmssLighttpdConfigNewerThanProcess($this->tempDir, $config, 150));

        $fragment = \pmssLighttpdManagedProxyFragment('rclone', 'fixtur01', 4002);
        $this->assertTrue(\pmssReplaceAccountFile($owner, $this->tempDir, $path, $fragment, 0640));
        clearstatcache();
        $this->assertTrue(\pmssLighttpdConfigNewerThanProcess($this->tempDir, $config, 150));
    }
}
