<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update.php';
require_once dirname(__DIR__, 2).'/update/users.php';
require_once dirname(__DIR__, 2).'/user/torrentPort.php';

class TorrentPortFrontendTest extends TestCase
{
    private $homeRoot;
    private $skelDir;
    private $user;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('homeRoot', 'pmss-torrent-frontends-home-');
        $this->pmssAssignTempDirProperty('skelDir', 'pmss-torrent-frontends-skel-');
        $this->user = 'user'.bin2hex(random_bytes(2));

        $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $this->pmssEnsureDir($this->skelDir.'/www');

        $this->pmssTrackEnvKeys(['HOME']);
        $this->pmssTrackEnvOverrides([
            'PMSS_HOME_DIR' => $this->homeRoot,
            'PMSS_SKEL_DIR' => $this->skelDir,
        ]);
    }

    public function testApplySkeletonFilesPatchesDelugeFrontendToUsePhpHelper(): void
    {
        $this->pmssWriteRelativeFile($this->skelDir, 'www/deluge.php', "<?php\nfunction startDeluge() {\n    shell_exec('nohup python3 /home/\$(whoami)/.delugePort.py; deluged -l /home/\$(whoami)/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l /home/\$(whoami)/.delugeWebLog -L info >> /dev/null 2>&1 &');\n}\n");

        \pmssUserApplySkeletonFiles($this->context());

        $content = (string) file_get_contents($this->pmssUserHomePath($this->homeRoot, $this->user, 'www/deluge.php'));
        $this->assertTrue(strpos($content, "require_once '/scripts/lib/user/torrentPort.php';") !== false);
        $this->assertTrue(strpos($content, 'pmssDelugePortEnsureCurrentUser') !== false);
        $this->assertTrue(strpos($content, '.delugePort.py') === false);
    }

    public function testApplySkeletonFilesPatchesQbittorrentFrontendToUsePhpHelper(): void
    {
        $this->pmssWriteRelativeFile($this->skelDir, 'www/qbittorrent.php', "<?php\nfunction startQbittorrent() {\n    passthru('python3 /home/\$(whoami)/.qbittorrentPort.py; zsh -c \"qbittorrent-nox -d\" >> /dev/null 2>&1 &');\n}\n");

        \pmssUserApplySkeletonFiles($this->context());

        $content = (string) file_get_contents($this->pmssUserHomePath($this->homeRoot, $this->user, 'www/qbittorrent.php'));
        $this->assertTrue(strpos($content, "require_once '/scripts/lib/user/torrentPort.php';") !== false);
        $this->assertTrue(strpos($content, 'pmssQbittorrentPortEnsureCurrentUser') !== false);
        $this->assertTrue(strpos($content, '.qbittorrentPort.py') === false);
    }

    public function testApplySkeletonFilesStopsPropagatingLegacyPythonHelpers(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/update/users/filesystem.php');

        $this->assertTrue(strpos($src, "        '.delugePort.py',") === false);
        $this->assertTrue(strpos($src, "        '.qbittorrentPort.py',") === false);
        $this->assertTrue(strpos($src, 'pmssDelugePortEnsureCurrentUser') !== false);
        $this->assertTrue(strpos($src, 'pmssQbittorrentPortEnsureCurrentUser') !== false);
    }

    public function testShippedDelugeFrontendUsesPhpPortHelper(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4).'/etc/skel/www/deluge.php');

        $this->assertTrue(strpos($src, "require_once __DIR__.'/../.scriptsInc.php';") !== false);
        $this->assertTrue(strpos($src, 'pmssFrontendToggleAction(') !== false);
        $this->assertTrue(strpos($src, "require_once '/scripts/lib/user/torrentPort.php';") !== false);
        $this->assertTrue(strpos($src, 'pmssDelugePortEnsureCurrentUser') !== false);
        $this->assertTrue(strpos($src, '.delugePort.py') === false);
    }

    public function testShippedQbittorrentFrontendUsesPhpPortHelper(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4).'/etc/skel/www/qbittorrent.php');

        $this->assertTrue(strpos($src, "require_once __DIR__.'/../.scriptsInc.php';") !== false);
        $this->assertTrue(strpos($src, 'pmssFrontendToggleAction(') !== false);
        $this->assertTrue(strpos($src, "require_once '/scripts/lib/user/torrentPort.php';") !== false);
        $this->assertTrue(strpos($src, 'pmssQbittorrentPortEnsureCurrentUser') !== false);
        $this->assertTrue(strpos($src, '.qbittorrentPort.py') === false);
    }

    public function testApplySkeletonFilesRemovesLegacyPhpXplorerFile(): void
    {
        $legacyPath = $this->pmssUserHomePath($this->homeRoot, $this->user, 'www/phpXplorer');
        $this->pmssWriteFile($legacyPath, "legacy\n");

        \pmssUserApplySkeletonFiles($this->context());

        $this->assertTrue(!file_exists($legacyPath));
    }

    public function testApplySkeletonFilesLeavesLegacyPhpXplorerDirectoryUntouched(): void
    {
        $legacyPath = $this->pmssUserHomePath($this->homeRoot, $this->user, 'www/phpXplorer');
        @mkdir($legacyPath, 0755, true);

        \pmssUserApplySkeletonFiles($this->context());

        $this->assertTrue(is_dir($legacyPath));
    }

    public function testApplySkeletonFilesRemovesDeadExtsearchEngineFiles(): void
    {
        foreach (['RARbgTorrentAPI.php', 'Demonoid.php', 'KAT.php'] as $engine) {
            $this->pmssWriteRelativeFile($this->pmssUserHomePath($this->homeRoot, $this->user), 'www/rutorrent/plugins/extsearch/engines/'.$engine, "<?php\n");
        }

        \pmssUserApplySkeletonFiles($this->context());

        foreach (['RARbgTorrentAPI.php', 'Demonoid.php', 'KAT.php'] as $engine) {
            $this->assertTrue(!file_exists($this->pmssUserHomePath($this->homeRoot, $this->user, 'www/rutorrent/plugins/extsearch/engines/'.$engine)));
        }
    }

    public function testApplySkeletonFilesRemovesDeadExtsearchEngineSymlinks(): void
    {
        $targetPath = $this->pmssUserHomePath($this->homeRoot, $this->user, 'www/rutorrent/plugins/extsearch/engines/target.php');
        $linkPath = $this->pmssUserHomePath($this->homeRoot, $this->user, 'www/rutorrent/plugins/extsearch/engines/KAT.php');
        $this->pmssWriteRelativeFile($this->pmssUserHomePath($this->homeRoot, $this->user), 'www/rutorrent/plugins/extsearch/engines/target.php', "<?php\n");
        @symlink($targetPath, $linkPath);

        \pmssUserApplySkeletonFiles($this->context());

        $this->assertTrue(!file_exists($linkPath));
        $this->assertTrue(file_exists($targetPath));
    }

    public function testApplySkeletonFilesLeavesDeadExtsearchEngineDirectoriesUntouched(): void
    {
        $directoryPath = $this->pmssUserHomePath($this->homeRoot, $this->user, 'www/rutorrent/plugins/extsearch/engines/Demonoid.php');
        @mkdir($directoryPath, 0755, true);

        \pmssUserApplySkeletonFiles($this->context());

        $this->assertTrue(is_dir($directoryPath));
    }

    public function testApplySkeletonFilesLeavesLiveExtsearchEnginesUntouched(): void
    {
        $liveEnginePath = $this->pmssUserHomePath($this->homeRoot, $this->user, 'www/rutorrent/plugins/extsearch/engines/Custom.php');
        $this->pmssWriteRelativeFile($this->pmssUserHomePath($this->homeRoot, $this->user), 'www/rutorrent/plugins/extsearch/engines/Custom.php', "<?php\n");

        \pmssUserApplySkeletonFiles($this->context());

        $this->assertTrue(file_exists($liveEnginePath));
    }

    public function testDelugePortEnsureUpdatesMismatchedPort(): void
    {
        $home = $this->pmssUserHomePath($this->homeRoot, $this->user);
        @mkdir($home.'/.config/deluge', 0755, true);
        file_put_contents($home.'/.delugePort', "34567\n");
        file_put_contents($home.'/.config/deluge/web.conf', "{\"file\":1,\"format\":1}{\"port\":12345,\"sessions\":[]}");

        $this->assertTrue(\pmssDelugePortEnsure($this->user, $home));

        $parsed = \pmssDelugeReadWebConf($home.'/.config/deluge/web.conf');
        $this->assertEquals(34567, $parsed['config']['port']);
    }

    public function testQbittorrentPortEnsureUpdatesMismatchedPort(): void
    {
        $home = $this->pmssUserHomePath($this->homeRoot, $this->user);
        @mkdir($home.'/.config/qBittorrent', 0755, true);
        file_put_contents($home.'/.qbittorrentPort', "45678\n");
        file_put_contents($home.'/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nWebUI\\Port=12345\n");

        $this->assertTrue(\pmssQbittorrentPortEnsure($this->user, $home));
        $updated = (string) file_get_contents($home.'/.config/qBittorrent/qBittorrent.conf');

        $this->assertTrue(strpos($updated, "WebUI\\Port=45678\n") !== false);
    }

    public function testQbittorrentPortEnsureRejectsMissingWebUiPort(): void
    {
        $home = $this->pmssUserHomePath($this->homeRoot, $this->user);
        @mkdir($home.'/.config/qBittorrent', 0755, true);
        file_put_contents($home.'/.qbittorrentPort', "45678\n");
        file_put_contents($home.'/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nLocale=en\n");

        $this->assertTrue(\pmssQbittorrentPortEnsure($this->user, $home) === false);
    }

    public function testCurrentUserContextRejectsSymlinkedHomePath(): void
    {
        $realHome = $this->pmssUserHomePath($this->homeRoot, $this->user);
        $linkedHome = $this->homeRoot.'/linked-home';
        symlink($realHome, $linkedHome);
        putenv('HOME='.$linkedHome);

        $this->assertSame(null, \pmssTorrentPortCurrentUserContext());
    }

    public function testQbittorrentPortEnsureRejectsSymlinkedHomeAncestor(): void
    {
        $realHome = $this->pmssUserHomePath($this->homeRoot, $this->user);
        $linkedHome = $this->homeRoot.'/linked-home-qbit';
        @mkdir($realHome.'/.config/qBittorrent', 0755, true);
        file_put_contents($realHome.'/.qbittorrentPort', "45678\n");
        file_put_contents($realHome.'/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nWebUI\\Port=12345\n");
        symlink($realHome, $linkedHome);

        $this->assertTrue(\pmssQbittorrentPortEnsure($this->user, $linkedHome) === false);
        $this->assertSame("[Preferences]\nWebUI\\Port=12345\n", (string) file_get_contents($realHome.'/.config/qBittorrent/qBittorrent.conf'));
    }

    public function testTorrentPortExpectedReadAcceptsValidRange(): void
    {
        $path = $this->pmssUserHomePath($this->homeRoot, $this->user, '.expected-port');
        file_put_contents($path, "45678\n");

        $this->assertEquals(45678, \pmssTorrentPortExpectedRead($path));
    }

    public function testTorrentPortExpectedReadRejectsInvalidValues(): void
    {
        $cases = [
            ['path' => $this->pmssUserHomePath($this->homeRoot, $this->user, '.expected-port-text'), 'content' => "abc\n"],
            ['path' => $this->pmssUserHomePath($this->homeRoot, $this->user, '.expected-port-low'), 'content' => "80\n"],
            ['path' => $this->pmssUserHomePath($this->homeRoot, $this->user, '.expected-port-high'), 'content' => "70000\n"],
        ];

        foreach ($cases as $case) {
            file_put_contents($case['path'], $case['content']);
            $this->assertTrue(\pmssTorrentPortExpectedRead($case['path']) === null);
        }
    }

    private function context(): array { return $this->pmssUserHomeContext($this->homeRoot, $this->user); }

}
