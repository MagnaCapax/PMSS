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
    private $envBackup = [];

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->homeRoot = sys_get_temp_dir().'/pmss-torrent-frontends-home-'.$suffix;
        $this->skelDir = sys_get_temp_dir().'/pmss-torrent-frontends-skel-'.$suffix;
        $this->user = 'user'.bin2hex(random_bytes(2));
        $this->envBackup = $this->stashEnv(['PMSS_HOME_DIR', 'PMSS_SKEL_DIR']);

        @mkdir($this->homeRoot.'/'.$this->user, 0755, true);
        @mkdir($this->skelDir.'/www', 0755, true);

        putenv('PMSS_HOME_DIR='.$this->homeRoot);
        putenv('PMSS_SKEL_DIR='.$this->skelDir);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv($this->envBackup);
        $this->cleanup($this->homeRoot);
        $this->cleanup($this->skelDir);
    }

    public function testApplySkeletonFilesPatchesDelugeFrontendToUsePhpHelper(): void
    {
        $this->writeSkelFile('www/deluge.php', "<?php\nfunction startDeluge() {\n    shell_exec('nohup python3 /home/\$(whoami)/.delugePort.py; deluged -l /home/\$(whoami)/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l /home/\$(whoami)/.delugeWebLog -L info >> /dev/null 2>&1 &');\n}\n");

        \pmssUserApplySkeletonFiles($this->context());

        $content = (string) file_get_contents($this->homePath('www/deluge.php'));
        $this->assertTrue(strpos($content, "require_once '/scripts/lib/user/torrentPort.php';") !== false);
        $this->assertTrue(strpos($content, 'pmssDelugePortEnsureCurrentUser') !== false);
        $this->assertTrue(strpos($content, '.delugePort.py') === false);
    }

    public function testApplySkeletonFilesPatchesQbittorrentFrontendToUsePhpHelper(): void
    {
        $this->writeSkelFile('www/qbittorrent.php', "<?php\nfunction startQbittorrent() {\n    passthru('python3 /home/\$(whoami)/.qbittorrentPort.py; zsh -c \"qbittorrent-nox -d\" >> /dev/null 2>&1 &');\n}\n");

        \pmssUserApplySkeletonFiles($this->context());

        $content = (string) file_get_contents($this->homePath('www/qbittorrent.php'));
        $this->assertTrue(strpos($content, "require_once '/scripts/lib/user/torrentPort.php';") !== false);
        $this->assertTrue(strpos($content, 'pmssQbittorrentPortEnsureCurrentUser') !== false);
        $this->assertTrue(strpos($content, '.qbittorrentPort.py') === false);
    }

    public function testDelugePortEnsureUpdatesMismatchedPort(): void
    {
        $home = $this->homePath();
        @mkdir($home.'/.config/deluge', 0755, true);
        file_put_contents($home.'/.delugePort', "34567\n");
        file_put_contents($home.'/.config/deluge/web.conf', "{\"file\":1,\"format\":1}{\"port\":12345,\"sessions\":[]}");

        $this->assertTrue(\pmssDelugePortEnsure($this->user, $home));

        $parsed = \pmssDelugeReadWebConf($home.'/.config/deluge/web.conf');
        $this->assertEquals(34567, $parsed['config']['port']);
    }

    public function testQbittorrentPortEnsureUpdatesMismatchedPort(): void
    {
        $home = $this->homePath();
        @mkdir($home.'/.config/qBittorrent', 0755, true);
        file_put_contents($home.'/.qbittorrentPort', "45678\n");
        file_put_contents($home.'/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nWebUI\\Port=12345\n");

        $this->assertTrue(\pmssQbittorrentPortEnsure($this->user, $home));
        $updated = (string) file_get_contents($home.'/.config/qBittorrent/qBittorrent.conf');

        $this->assertTrue(strpos($updated, "WebUI\\Port=45678\n") !== false);
    }

    public function testQbittorrentPortEnsureRejectsMissingWebUiPort(): void
    {
        $home = $this->homePath();
        @mkdir($home.'/.config/qBittorrent', 0755, true);
        file_put_contents($home.'/.qbittorrentPort', "45678\n");
        file_put_contents($home.'/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nLocale=en\n");

        $this->assertTrue(\pmssQbittorrentPortEnsure($this->user, $home) === false);
    }

    private function context(): array
    {
        return ['user' => $this->user, 'home' => $this->homePath()];
    }

    private function homePath(string $relative = ''): string
    {
        $base = $this->homeRoot.'/'.$this->user;
        return $relative === '' ? $base : $base.'/'.$relative;
    }

    private function writeSkelFile(string $relative, string $content): void
    {
        $path = $this->skelDir.'/'.$relative;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
    }

    private function stashEnv(array $names): array
    {
        $previous = [];
        foreach ($names as $name) {
            $previous[$name] = getenv($name);
        }
        return $previous;
    }

    private function restoreEnv(array $previous): void
    {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name.'='.$value);
            }
        }
    }

    private function cleanup(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
