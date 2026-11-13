<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/scripts/lib/rutorrentPlugins.php';

class CheckRutorrentPluginsTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-rutorrent-plugins-']; }

    public function testSyncUserRejectsInvalidUsername(): void
    {
        $commands = array();
        $ok = \pmssCheckRutorrentPluginsSyncUser('bad;user', 'allow=1', $this->pmssPaths(), function ($command) use (&$commands): int {
            $commands[] = $command;
            return 0;
        });

        $this->assertTrue($ok === false);
        $this->assertEquals(array(), $commands);
    }

    public function testSyncUserRemovesDiskspaceInstallsQuotaAndWritesAccessIni(): void
    {
        $paths = $this->pmssPaths();
        $this->pmssCreateUserTree('fixtur01', true, false);

        $commands = array();
        $ok = \pmssCheckRutorrentPluginsSyncUser('fixtur01', "allow=1\n", $paths, function ($command, $step, $username) use (&$commands): int {
            $commands[] = array($command, $step, $username);
            return 0;
        });

        $this->assertTrue($ok === true);
        $this->assertEquals("allow=1\n", (string) file_get_contents($paths['homeRoot'].'/fixtur01/www/rutorrent/conf/access.ini'));
        $this->assertEquals(array(
            array('rm -rf '.escapeshellarg($paths['homeRoot'].'/fixtur01/www/rutorrent/plugins/diskspace'), 'remove_diskspace', 'fixtur01'),
            array('cp -rp '.escapeshellarg($paths['skelRoot'].'/www/rutorrent/plugins/hddquota').' '.escapeshellarg($paths['homeRoot'].'/fixtur01/www/rutorrent/plugins'), 'install_hddquota', 'fixtur01'),
            array('chown '.escapeshellarg('fixtur01:fixtur01').' '.escapeshellarg($paths['homeRoot'].'/fixtur01/www/rutorrent/plugins/hddquota'), 'chown_hddquota', 'fixtur01'),
            array('chmod -R 777 '.escapeshellarg($paths['homeRoot'].'/fixtur01/www/rutorrent/plugins/hddquota'), 'chmod_hddquota', 'fixtur01'),
        ), $commands);
    }

    public function testSyncUserSkipsInstallWhenQuotaAlreadyExists(): void
    {
        $paths = $this->pmssPaths();
        $this->pmssCreateUserTree('fixtur01', false, true);

        $commands = array();
        $ok = \pmssCheckRutorrentPluginsSyncUser('fixtur01', "allow=1\n", $paths, function ($command) use (&$commands): int {
            $commands[] = $command;
            return 0;
        });

        $this->assertTrue($ok === true);
        $this->assertEquals(array(), $commands);
    }

    public function testSyncUserPreservesExistingAccessIniMode(): void
    {
        $paths = $this->pmssPaths();
        $this->pmssCreateUserTree('fixtur01', false, true);

        $accessPath = $paths['homeRoot'].'/fixtur01/www/rutorrent/conf/access.ini';
        $this->pmssWriteFile($accessPath, "allow=0\n");
        chmod($accessPath, 0640);

        $ok = \pmssCheckRutorrentPluginsSyncUser('fixtur01', "allow=1\n", $paths, self::pmssMakeCallbacks(['run' => 0])['run']);

        $this->assertTrue($ok === true);
        $this->assertEquals("allow=1\n", (string) file_get_contents($accessPath));
        $this->assertEquals(0640, fileperms($accessPath) & 0777);
    }

    public function testSyncUserReturnsFalseWhenPluginDirectoryMissing(): void
    {
        $paths = $this->pmssPaths();
        $this->pmssEnsureDir($paths['homeRoot'].'/fixtur01/www/rutorrent/conf', 0755);

        $ok = \pmssCheckRutorrentPluginsSyncUser('fixtur01', "allow=1\n", $paths);

        $this->assertTrue($ok === false);
        $this->assertTrue(!is_file($paths['homeRoot'].'/fixtur01/www/rutorrent/conf/access.ini'));
    }

    public function testSyncUserReturnsFalseWhenAccessIniDirectoryMissing(): void
    {
        $paths = $this->pmssPaths();
        $this->pmssEnsureDir($paths['homeRoot'].'/fixtur01/www/rutorrent/plugins', 0755);
        $this->pmssEnsureDir($paths['skelRoot'].'/www/rutorrent/plugins/hddquota', 0755);

        $ok = \pmssCheckRutorrentPluginsSyncUser('fixtur01', "allow=1\n", $paths, self::pmssMakeCallbacks(['run' => 0])['run']);

        $this->assertTrue($ok === false);
    }

    public function testSyncUserRejectsSymlinkedAccessIniTarget(): void
    {
        $paths = $this->pmssPaths();
        $this->pmssCreateUserTree('fixtur01', false, true);

        $outsideDir = $this->pmssMakeTempDir('pmss-rutorrent-access-target-');
        $outsidePath = $outsideDir.'/access.ini';
        $this->pmssWriteFile($outsidePath, "allow=0\n");

        $accessPath = $paths['homeRoot'].'/fixtur01/www/rutorrent/conf/access.ini';
        $this->pmssCreateSymlinkOrSkip($outsidePath, $accessPath);

        $ok = \pmssCheckRutorrentPluginsSyncUser('fixtur01', "allow=1\n", $paths, self::pmssMakeCallbacks(['run' => 0])['run']);

        $this->assertTrue($ok === false);
        $this->assertEquals("allow=0\n", (string) file_get_contents($outsidePath));
        $this->assertTrue(is_link($accessPath));
    }

    private function pmssPaths(): array
    {
        return array(
            'homeRoot' => $this->tempDir.'/home',
            'skelRoot' => $this->tempDir.'/skel',
        );
    }

    private function pmssCreateUserTree(string $username, bool $withDiskspace, bool $withHddquota): void
    {
        $paths = $this->pmssPaths();
        $pluginsDir = $paths['homeRoot'].'/'.$username.'/www/rutorrent/plugins';
        $confDir = $paths['homeRoot'].'/'.$username.'/www/rutorrent/conf';
        $skelQuotaDir = $paths['skelRoot'].'/www/rutorrent/plugins/hddquota';

        $this->pmssEnsureDir($pluginsDir, 0755);
        $this->pmssEnsureDir($confDir, 0755);
        $this->pmssWriteFile($skelQuotaDir.'/quota.txt', "quota\n");

        if ($withDiskspace) {
            $this->pmssEnsureDir($pluginsDir.'/diskspace', 0755);
        }
        if ($withHddquota) {
            $this->pmssEnsureDir($pluginsDir.'/hddquota', 0755);
        }
    }
}
