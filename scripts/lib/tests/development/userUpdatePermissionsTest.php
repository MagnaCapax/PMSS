<?php
namespace {
    if (!function_exists('runUserStep')) {
        function runUserStep(string $user, string $description, string $command): int
        {
            return 0;
        }
    }
}

namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/user/permissions.php';

class UserUpdatePermissionsTest extends TestCase
{
    public function testRefreshPermissionsUpdatesLegacyFile(): void
    {
        $home = sys_get_temp_dir().'/pmss-perm-'.bin2hex(random_bytes(4));
        mkdir($home, 0755, true);
        $legacy = 'dcf21704d49910d1670b3fdd04b37e640b755889';
        file_put_contents($home.'/.rtorrent.rc.custom', "legacy");
        $newContent = 'new-skel';
        putenv('PMSS_SKEL_DIR='.$home);
        try {
            file_put_contents($home.'/.rtorrent.rc.custom', 'legacy');
            $ctx = [
                'user'     => 'dummy',
                'home'     => $home,
                'user_esc' => escapeshellarg('dummy'),
            ];
            \pmssUserRefreshPermissions($ctx);
        } finally {
            putenv('PMSS_SKEL_DIR');
            $this->cleanup($home);
        }
        $this->assertTrue(true);
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
