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

    private function pmssTarField(string $value, int $length): string
    {
        return substr($value.str_repeat("\0", $length), 0, $length);
    }

    private function pmssTarOctal(int $value, int $length): string
    {
        return str_pad(decoct($value), $length - 1, '0', STR_PAD_LEFT)."\0";
    }

    private function pmssTarHeader(string $name, string $type, string $content = '', string $linkName = ''): string
    {
        $this->assertTrue(strlen($name) <= 100, 'Fixture tar member name too long: '.$name);
        $size = $type === '0' ? strlen($content) : 0;
        $header = $this->pmssTarField($name, 100)
            .$this->pmssTarOctal($type === '5' ? 0755 : 0644, 8)
            .$this->pmssTarOctal(0, 8)
            .$this->pmssTarOctal(0, 8)
            .$this->pmssTarOctal($size, 12)
            .$this->pmssTarOctal(1, 12)
            .str_repeat(' ', 8)
            .$type
            .$this->pmssTarField($linkName, 100)
            .$this->pmssTarField('ustar', 6)
            .$this->pmssTarField('00', 2)
            .$this->pmssTarField('pmss', 32)
            .$this->pmssTarField('pmss', 32)
            .$this->pmssTarOctal(0, 8)
            .$this->pmssTarOctal(0, 8)
            .$this->pmssTarField('', 155)
            .$this->pmssTarField('', 12);

        $checksum = 0;
        for ($i = 0; $i < strlen($header); $i++) {
            $checksum += ord($header[$i]);
        }

        return substr_replace($header, str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);
    }

    private function pmssTarPayload(string $content): string
    {
        $padding = (512 - (strlen($content) % 512)) % 512;
        return $content.str_repeat("\0", $padding);
    }

    private function pmssWriteTarGzArchive(array $members, string $filename = 'pmss-torrent-config-test.tar.gz'): string
    {
        if (!function_exists('gzencode') || !class_exists(\PharData::class)) {
            throw new SkipTest('gzip/PharData support is required for restore archive tests');
        }

        $tar = '';
        foreach ($members as $member) {
            $name = (string) $member['name'];
            $type = isset($member['type']) ? (string) $member['type'] : '0';
            $content = isset($member['content']) ? (string) $member['content'] : '';
            $linkName = isset($member['linkName']) ? (string) $member['linkName'] : '';
            $tar .= $this->pmssTarHeader($name, $type, $content, $linkName);
            if ($type === '0') {
                $tar .= $this->pmssTarPayload($content);
            }
        }
        $tar .= str_repeat("\0", 1024);

        $encoded = gzencode($tar);
        $this->assertTrue(is_string($encoded), 'Expected fixture tar archive to gzip');
        return $this->pmssWriteFile($this->home.'/'.$filename, $encoded);
    }

    private function pmssArchiveFileContent(string $archivePath, string $member): string
    {
        $archive = new \PharData($archivePath);
        $prefix = 'phar://'.$archivePath.'/';
        foreach (new \RecursiveIteratorIterator($archive, \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if ($entry->getPathname() === $prefix.$member) {
                return $entry->getContent();
            }
        }

        $this->fail('Expected archive member: '.$member);
        return '';
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

    public function testRestoreSkipsTraversalAbsoluteAndSymlinkMembers(): void
    {
        $this->pmssWriteRelativeFile($this->home, '.rtorrent.rc', 'old-config');
        $archive = $this->pmssWriteTarGzArchive(array(
            array('name' => '.rtorrent.rc', 'content' => 'restored-config'),
            array('name' => '../escape.txt', 'content' => 'traversal'),
            array('name' => $this->homeRoot.'/absolute.txt', 'content' => 'absolute'),
            array('name' => 'session', 'type' => '5'),
            array('name' => 'session/link', 'type' => '2', 'linkName' => '../escape-link'),
        ));

        $result = \pmssCustomerBackupRestore($this->home, $archive);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('restored-config', (string) file_get_contents($this->home.'/.rtorrent.rc'));
        $this->assertFalse(file_exists($this->homeRoot.'/escape.txt'), 'Traversal member must not write outside the home');
        $this->assertFalse(file_exists($this->homeRoot.'/absolute.txt'), 'Absolute member must not write outside the allowlist');
        $this->assertFalse(file_exists($this->home.'/session/link'), 'Symlink member under an allowlisted path must be skipped');
    }

    public function testRestoreSkipsMembersOutsideBackupAllowlist(): void
    {
        $this->pmssWriteRelativeFile($this->home, '.rtorrent.rc', 'old-config');
        $archive = $this->pmssWriteTarGzArchive(array(
            array('name' => '.rtorrent.rc', 'content' => 'new-config'),
            array('name' => 'data', 'type' => '5'),
            array('name' => 'data/media.bin', 'content' => 'media'),
        ));

        $result = \pmssCustomerBackupRestore($this->home, $archive);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('new-config', (string) file_get_contents($this->home.'/.rtorrent.rc'));
        $this->assertFalse(file_exists($this->home.'/data/media.bin'), 'Non-backup paths must not be extracted');
    }

    public function testValidBackupArchiveRestoresAllowlistedPathsAndLogsOutcome(): void
    {
        $this->pmssWriteRelativeFile($this->home, '.rtorrent.rc', 'old-config');
        $archive = $this->pmssWriteTarGzArchive(array(
            array('name' => '.rtorrent.rc', 'content' => 'new-config'),
            array('name' => 'session', 'type' => '5'),
            array('name' => 'session/state.torrent', 'content' => 'resume-state'),
            array('name' => '.config/deluge', 'type' => '5'),
            array('name' => '.config/deluge/auth', 'content' => 'service-secret'),
        ));

        $result = \pmssCustomerBackupRestore($this->home, $archive);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('new-config', (string) file_get_contents($this->home.'/.rtorrent.rc'));
        $this->assertSame('resume-state', (string) file_get_contents($this->home.'/session/state.torrent'));
        $this->assertSame('service-secret', (string) file_get_contents($this->home.'/.config/deluge/auth'));
        $this->assertTrue(is_file($result['snapshotPath']), 'Restore must report the pre-restore snapshot path');
        $this->assertStringContainsAllStrings(array('config_restore status=success', 'snapshot='.$result['snapshotPath']), (string) file_get_contents($this->home.'/rTorrentLog'));
    }

    public function testPreRestoreSnapshotCapturesCurrentStateBeforeExtraction(): void
    {
        $this->pmssWriteRelativeFile($this->home, '.rtorrent.rc', 'old-config');
        $archive = $this->pmssWriteTarGzArchive(array(
            array('name' => '.rtorrent.rc', 'content' => 'new-config'),
        ));

        $result = \pmssCustomerBackupRestore($this->home, $archive);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('new-config', (string) file_get_contents($this->home.'/.rtorrent.rc'));
        $this->assertMatches('#/\.pmss-pre-restore-[0-9]{8}-[0-9]{6}(?:-[0-9]{2})?\.tar\.gz\z#', $result['snapshotPath']);
        $this->assertSame('old-config', $this->pmssArchiveFileContent($result['snapshotPath'], '.rtorrent.rc'));
    }

    public function testRestoreRefusesRtorrentConfigWhenSessionLockExists(): void
    {
        $this->pmssWriteRelativeFile($this->home, '.rtorrent.rc', 'old-config');
        $this->pmssWriteRelativeFile($this->home, 'session/rtorrent.lock', "12345:+session\n");
        $archive = $this->pmssWriteTarGzArchive(array(
            array('name' => '.rtorrent.rc', 'content' => 'new-config'),
        ));

        $result = \pmssCustomerBackupRestore($this->home, $archive);

        $this->assertFalse($result['ok']);
        $this->assertSame('Stop your torrent client before restoring configuration, then try again.', $result['message']);
        $this->assertSame('old-config', (string) file_get_contents($this->home.'/.rtorrent.rc'));
        $this->assertSame(array(), glob($this->home.'/.pmss-pre-restore-*.tar.gz') ?: array(), 'Daemon-safety refusal must happen before snapshot/extract');
    }

    public function testWelcomeRoutesAndExplainsBackupBeforeRendering(): void
    {
        $welcome = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $route = strpos($welcome, "\$_GET['backup'] === 'download'");
        $restoreRoute = strpos($welcome, "\$_GET['restore'] === 'config'");
        $pageState = strpos($welcome, '$pageState = pmssWelcomePageStateBuild();');

        $this->assertTrue($route !== false && $pageState !== false && $route < $pageState, 'Backup response must run before welcome HTML rendering.');
        $this->assertTrue($restoreRoute !== false && $pageState !== false && $restoreRoute < $pageState, 'Restore response must run before welcome HTML rendering.');
        $this->assertSame(1, substr_count($welcome, 'name="configBackup'), 'Welcome must expose only the streamed backup control.');
        $this->assertSame(1, substr_count($welcome, 'name="torrentConfigRestore"'), 'Welcome must expose the guided restore control once.');
        $this->assertStringContainsAllStrings(array(
            '<form method="get" action="welcome.php">',
            '<input type="hidden" name="backup" value="download" />',
            '<input type="submit" name="configBackupDownload" value="Download torrent configuration backup" />',
            '<form method="post" action="welcome.php?restore=config">',
            '<input type="text" name="archivePath"',
            '<input type="submit" name="torrentConfigRestore" value="Restore torrent configuration" />',
            'Media files are not included.',
            'private tracker URLs and separate application credentials',
        ), $welcome);
    }
}
