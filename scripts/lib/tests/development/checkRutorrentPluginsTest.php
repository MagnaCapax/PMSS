<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/scripts/lib/rutorrentPlugins.php';

class CheckRutorrentPluginsTest extends TestCase
{
    private $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = $this->pmssMakeTempDir('pmss-rutorrent-plugins-');
    }

    protected function tearDown(): void
    {
        $this->pmssRemoveTree($this->tempDir);
    }

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
        $this->pmssCreateUserTree('alice', true, false);

        $commands = array();
        $ok = \pmssCheckRutorrentPluginsSyncUser('alice', "allow=1\n", $paths, function ($command, $step, $username) use (&$commands): int {
            $commands[] = array($command, $step, $username);
            return 0;
        });

        $this->assertTrue($ok === true);
        $this->assertEquals("allow=1\n", (string) file_get_contents($paths['homeRoot'].'/alice/www/rutorrent/conf/access.ini'));
        $this->assertEquals(array(
            array('rm -rf '.escapeshellarg($paths['homeRoot'].'/alice/www/rutorrent/plugins/diskspace'), 'remove_diskspace', 'alice'),
            array('cp -rp '.escapeshellarg($paths['skelRoot'].'/www/rutorrent/plugins/hddquota').' '.escapeshellarg($paths['homeRoot'].'/alice/www/rutorrent/plugins'), 'install_hddquota', 'alice'),
            array('chown '.escapeshellarg('alice:alice').' '.escapeshellarg($paths['homeRoot'].'/alice/www/rutorrent/plugins/hddquota'), 'chown_hddquota', 'alice'),
            array('chmod -R 777 '.escapeshellarg($paths['homeRoot'].'/alice/www/rutorrent/plugins/hddquota'), 'chmod_hddquota', 'alice'),
        ), $commands);
    }

    public function testSyncUserSkipsInstallWhenQuotaAlreadyExists(): void
    {
        $paths = $this->pmssPaths();
        $this->pmssCreateUserTree('alice', false, true);

        $commands = array();
        $ok = \pmssCheckRutorrentPluginsSyncUser('alice', "allow=1\n", $paths, function ($command) use (&$commands): int {
            $commands[] = $command;
            return 0;
        });

        $this->assertTrue($ok === true);
        $this->assertEquals(array(), $commands);
    }

    public function testSyncUserPreservesExistingAccessIniMode(): void
    {
        $paths = $this->pmssPaths();
        $this->pmssCreateUserTree('alice', false, true);

        $accessPath = $paths['homeRoot'].'/alice/www/rutorrent/conf/access.ini';
        file_put_contents($accessPath, "allow=0\n");
        chmod($accessPath, 0640);

        $ok = \pmssCheckRutorrentPluginsSyncUser('alice', "allow=1\n", $paths, function (): int {
            return 0;
        });

        $this->assertTrue($ok === true);
        $this->assertEquals("allow=1\n", (string) file_get_contents($accessPath));
        $this->assertEquals(0640, fileperms($accessPath) & 0777);
    }

    public function testSyncUserReturnsFalseWhenPluginDirectoryMissing(): void
    {
        $paths = $this->pmssPaths();
        @mkdir($paths['homeRoot'].'/alice/www/rutorrent/conf', 0755, true);

        $ok = \pmssCheckRutorrentPluginsSyncUser('alice', "allow=1\n", $paths);

        $this->assertTrue($ok === false);
        $this->assertTrue(!is_file($paths['homeRoot'].'/alice/www/rutorrent/conf/access.ini'));
    }

    public function testSyncUserReturnsFalseWhenAccessIniDirectoryMissing(): void
    {
        $paths = $this->pmssPaths();
        @mkdir($paths['homeRoot'].'/alice/www/rutorrent/plugins', 0755, true);
        @mkdir($paths['skelRoot'].'/www/rutorrent/plugins/hddquota', 0755, true);

        $ok = \pmssCheckRutorrentPluginsSyncUser('alice', "allow=1\n", $paths, function (): int {
            return 0;
        });

        $this->assertTrue($ok === false);
    }

    public function testSyncUserRejectsSymlinkedAccessIniTarget(): void
    {
        $paths = $this->pmssPaths();
        $this->pmssCreateUserTree('alice', false, true);

        $outsideDir = $this->pmssMakeTempDir('pmss-rutorrent-access-target-');
        $outsidePath = $outsideDir.'/access.ini';
        file_put_contents($outsidePath, "allow=0\n");

        $accessPath = $paths['homeRoot'].'/alice/www/rutorrent/conf/access.ini';
        $this->pmssCreateSymlinkOrSkip($outsidePath, $accessPath);

        $ok = \pmssCheckRutorrentPluginsSyncUser('alice', "allow=1\n", $paths, function (): int {
            return 0;
        });

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

        @mkdir($pluginsDir, 0755, true);
        @mkdir($confDir, 0755, true);
        @mkdir($skelQuotaDir, 0755, true);
        file_put_contents($skelQuotaDir.'/quota.txt', "quota\n");

        if ($withDiskspace) {
            @mkdir($pluginsDir.'/diskspace', 0755, true);
        }
        if ($withHddquota) {
            @mkdir($pluginsDir.'/hddquota', 0755, true);
        }
    }
}
