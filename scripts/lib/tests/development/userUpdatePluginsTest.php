<?php
namespace {
    if (!function_exists('runUserStep')) {
        function runUserStep(string $user, string $description, string $command): int
        {
            // simulate success without executing commands
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
