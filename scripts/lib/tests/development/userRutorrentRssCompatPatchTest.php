<?php
namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/users.php';

class UserRutorrentRssCompatPatchTest extends TestCase
{
    public function testCompatibilityPatchesLegacyRssObFlushCall(): void
    {
        $home = $this->createHome();
        $path = $home.'/www/rutorrent/plugins/rss/action.php';
        file_put_contents($path, "before\nob_flush();\nafter\n");

        try {
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $content = (string) file_get_contents($path);

            $this->assertTrue(strpos($content, '@ob_flush();') !== false);
            $this->assertTrue(strpos($content, "\nob_flush();\n") === false);
        } finally {
            $this->cleanup($home);
        }
    }

    public function testCompatibilityLeavesPatchedRssObFlushCallUntouched(): void
    {
        $home = $this->createHome();
        $path = $home.'/www/rutorrent/plugins/rss/action.php';
        file_put_contents($path, "before\n@ob_flush();\nafter\n");

        try {
            $before = (string) file_get_contents($path);
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $this->assertEquals($before, (string) file_get_contents($path));
        } finally {
            $this->cleanup($home);
        }
    }

    public function testCompatibilitySkipsMissingRssTarget(): void
    {
        $home = $this->createHome();

        try {
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $this->assertTrue(!file_exists($home.'/www/rutorrent/plugins/rss/action.php'));
        } finally {
            $this->cleanup($home);
        }
    }

    public function testCompatibilitySkipsSymlinkedRssTarget(): void
    {
        $home = $this->createHome();
        $target = $this->tempPath('patch-symlink-target');
        $link = $home.'/www/rutorrent/plugins/rss/action.php';
        file_put_contents($target, "before\nob_flush();\nafter\n");
        @symlink($target, $link);

        try {
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $this->assertEquals("before\nob_flush();\nafter\n", (string) file_get_contents($target));
        } finally {
            @unlink($target);
            $this->cleanup($home);
        }
    }

    public function testCompatibilityLeavesNonMatchingRssContentUntouched(): void
    {
        $home = $this->createHome();
        $path = $home.'/www/rutorrent/plugins/rss/action.php';
        file_put_contents($path, "before\nflush();\nafter\n");

        try {
            $before = (string) file_get_contents($path);
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $this->assertEquals($before, (string) file_get_contents($path));
        } finally {
            $this->cleanup($home);
        }
    }

    private function createHome(): string
    {
        $home = sys_get_temp_dir().'/pmss-rutorrent-rss-home-'.bin2hex(random_bytes(4));
        @mkdir($home.'/www/rutorrent/plugins/rss', 0755, true);
        return $home;
    }

    private function tempPath(string $suffix): string
    {
        return sys_get_temp_dir().'/pmss-user-rutorrent-rss-'.$suffix.'-'.bin2hex(random_bytes(4)).'.php';
    }

    private function cleanup(string $path): void
    {
        if (!file_exists($path)) {
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

}
