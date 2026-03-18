<?php
namespace {
    if (!function_exists('runUserStep')) {
        function runUserStep(string $user, string $description, string $command): int
        {
            $GLOBALS['PMSS_PROFILE'][] = [
                'description' => $description,
                'command' => $command,
            ];
            return 0;
        }
    }
}

namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/user/plugins.php';

class UserUpdatePluginsTest extends TestCase
{
    public function testEnsurePluginsReportsMissingSource(): void
    {
        $home = sys_get_temp_dir().'/pmss-plugins-home-'.bin2hex(random_bytes(4));
        @mkdir($home, 0755, true);

        $previousDryRun = getenv('PMSS_DRY_RUN');
        $previousSkel   = getenv('PMSS_SKEL_DIR');
        putenv('PMSS_DRY_RUN=1');
        putenv('PMSS_SKEL_DIR=' . sys_get_temp_dir().'/does-not-exist');

        $GLOBALS['PMSS_PROFILE'] = [];
        try {
            $ctx = [
                'user'     => 'dummy',
                'home'     => $home,
                'user_esc' => escapeshellarg('dummy'),
            ];
            \pmssUserEnsurePlugins($ctx);

            $expectedSource = sys_get_temp_dir().'/does-not-exist/www/rutorrent/plugins/unpack';
            $expectedDest   = $home.'/www/rutorrent/plugins/unpack';
            $expectedCmd = sprintf(
                'cp -Rp %s %s',
                escapeshellarg($expectedSource),
                escapeshellarg($expectedDest)
            );
            $cmd = null;
            foreach (($GLOBALS['PMSS_PROFILE'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $description = (string) ($entry['description'] ?? '');
                if (strpos($description, 'Installing unpack plugin') === false) {
                    continue;
                }
	                $cmd = isset($entry['command']) ? (string) $entry['command'] : null;
	                break;
	            }
	            $this->assertEquals($expectedCmd, (string) $cmd);
	        } finally {
	            if ($previousSkel === false) {
	                putenv('PMSS_SKEL_DIR');
            } else {
                putenv('PMSS_SKEL_DIR='.$previousSkel);
            }
            if ($previousDryRun === false) {
                putenv('PMSS_DRY_RUN');
            } else {
                putenv('PMSS_DRY_RUN='.$previousDryRun);
            }
            $this->cleanup($home);
        }
    }

    public function testEnsurePluginsOwnsRetrackerCleanupAndDirectoryBootstrap(): void
    {
        $home = sys_get_temp_dir().'/pmss-plugins-retracker-'.bin2hex(random_bytes(4));
        $settingsDir = $home.'/www/rutorrent/share/users/dummy/settings';
        @mkdir($home.'/www/rutorrent/plugins/unpack', 0755, true);
        @mkdir($settingsDir, 0755, true);
        file_put_contents(
            $settingsDir.'/retrackers.dat',
            'O:11:"rRetrackers":4:{s:4:"hash";s:14:"retrackers.dat";s:4:"list";a:1:{i:0;a:1:{i:0;s:33:"http://149.5.241.17:6969/announce";}}s:14:"dontAddPrivate";s:1:"1";s:10:"addToBegin";s:1:"1";}'
        );

        $GLOBALS['PMSS_PROFILE'] = [];

        try {
            \pmssUserEnsurePlugins([
                'user'     => 'dummy',
                'home'     => $home,
                'user_esc' => escapeshellarg('dummy'),
            ]);

            $this->assertTrue(!file_exists($settingsDir.'/retrackers.dat'));
            $this->assertEquals(
                sprintf('mkdir -p %s', escapeshellarg($home.'/www/rutorrent/share/users/dummy/torrents')),
                $this->findCommand('Creating ruTorrent torrents directory')
            );
            $this->assertEquals(
                sprintf('mkdir -p %s', escapeshellarg($home.'/www/rutorrent/share/settings/rss')),
                $this->findCommand('Creating ruTorrent RSS settings directory')
            );
        } finally {
            $this->cleanup($home);
        }
    }

    private function findCommand(string $needle): ?string
    {
        foreach (($GLOBALS['PMSS_PROFILE'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $description = (string) ($entry['description'] ?? '');
            if (strpos($description, $needle) === false) {
                continue;
            }
            return isset($entry['command']) ? (string) $entry['command'] : null;
        }

        return null;
    }

    private function cleanup(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}

}
