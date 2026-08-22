<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsInc.php';

class CustomerBackupTest extends TestCase
{
    private $homeRoot = '';
    private $home = '';

    protected function setUp(): void
    {
        $this->homeRoot = $this->pmssMakeTempDir('pmss-customer-backup-');
        $this->home = $this->pmssEnsureDir($this->homeRoot.'/alice');
        $this->pmssTrackEnvOverrides(array('PMSS_HOME_DIR' => $this->homeRoot));
    }

    public function testCandidateSetCoversTorrentClientsWithoutMediaData(): void
    {
        $candidates = \pmssCustomerBackupRelativePaths();

        $this->assertStringContainsAllStrings(array(
            '.rtorrent.rc',
            'session',
            '.config/deluge',
            '.config/qBittorrent',
            '.local/share/qBittorrent/BT_backup',
            '.local/share/data/qBittorrent/BT_backup',
            '.config/pmss/rutorrent/users',
            '.local/share/pmss/rutorrent/share',
        ), implode("\n", $candidates));
        foreach (array('data', 'watch', 'wireguard.txt', '.ssh') as $excluded) {
            $this->assertFalse(in_array($excluded, $candidates, true), 'Bulk/private account path must stay outside the backup: '.$excluded);
        }
    }

    public function testEntryDiscoveryKeepsFixedOrderAndSkipsMissingPaths(): void
    {
        $this->pmssWriteRelativeFile($this->home, '.rtorrent.rc', 'config');
        $this->pmssWriteRelativeFile($this->home, 'session/state.torrent', 'torrent');
        $this->pmssWriteRelativeFile($this->home, '.config/deluge/auth', 'service-secret');

        $this->assertSame(
            array('.rtorrent.rc', 'session', '.config/deluge'),
            \pmssCustomerBackupEntriesRead($this->home)
        );
    }

    public function testEntryDiscoveryRejectsSymlinkedTopLevelCandidate(): void
    {
        $outside = $this->pmssWriteRelativeFile($this->homeRoot, 'outside/qBittorrent.conf', 'outside');
        $this->pmssEnsureDir($this->home.'/.config');
        symlink(dirname($outside), $this->home.'/.config/qBittorrent');

        $this->assertSame(array(), \pmssCustomerBackupEntriesRead($this->home));
    }

    public function testCommandBuilderRejectsEmptyUnknownAndUnsafeInputs(): void
    {
        $this->assertSame('', \pmssCustomerBackupTarCommandBuild($this->home, array()));
        $this->assertSame('', \pmssCustomerBackupTarCommandBuild($this->home, array('../escape')));
        $this->assertSame('', \pmssCustomerBackupTarCommandBuild($this->home, array('--checkpoint-action=exec=sh')));

        $linkedHome = $this->homeRoot.'/linked-home';
        symlink($this->home, $linkedHome);
        $this->assertSame('', \pmssCustomerBackupTarCommandBuild($linkedHome, array('session')));
    }

    public function testArchiveContainsOnlyDiscoveredConfigurationAndState(): void
    {
        $this->pmssWriteRelativeFile($this->home, '.rtorrent.rc', 'config');
        $this->pmssWriteRelativeFile($this->home, 'session/state.torrent', 'torrent');
        $this->pmssWriteRelativeFile($this->home, '.config/deluge/auth', 'service-secret');
        $this->pmssWriteRelativeFile($this->home, 'data/media.bin', 'media');

        $command = \pmssCustomerBackupTarCommandBuild($this->home, \pmssCustomerBackupEntriesRead($this->home));
        $archive = $this->homeRoot.'/backup.tar.gz';
        $errors = $this->homeRoot.'/tar-errors.log';
        $output = array();
        $returnCode = 1;
        exec($command.' > '.escapeshellarg($archive).' 2> '.escapeshellarg($errors), $output, $returnCode);
        $this->assertSame(0, $returnCode, (string) @file_get_contents($errors));

        $members = array();
        exec('/bin/tar -tzf '.escapeshellarg($archive).' 2>&1', $members, $returnCode);
        $manifest = implode("\n", $members);
        $this->assertSame(0, $returnCode, $manifest);
        $this->assertStringContainsAllStrings(array('.rtorrent.rc', 'session/state.torrent', '.config/deluge/auth'), $manifest);
        $this->assertStringNotContainsString('data/media.bin', $manifest);
    }

    public function testWelcomeRoutesAndExplainsBackupBeforeRendering(): void
    {
        $welcome = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $route = strpos($welcome, "\$_GET['backup'] === 'download'");
        $pageState = strpos($welcome, '$pageState = pmssWelcomePageStateBuild();');

        $this->assertTrue($route !== false && $pageState !== false && $route < $pageState, 'Backup response must run before welcome HTML rendering.');
        $this->assertStringContainsAllStrings(array(
            'Download torrent configuration backup',
            'Media files are not included.',
            'private tracker URLs and separate application credentials',
        ), $welcome);
    }
}
