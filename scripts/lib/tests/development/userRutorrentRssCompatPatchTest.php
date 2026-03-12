<?php
namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/user/rutorrent.php';

class UserRutorrentRssCompatPatchTest extends TestCase
{
    public function testPatchAddsSuppressionToRssObFlushCall(): void
    {
        $path = $this->tempPath('patch-adds');
        file_put_contents($path, "before\nob_flush();\nafter\n");

        $patched = \pmssUserRutorrentRssObFlushPatchApply($path);
        $content = (string) file_get_contents($path);

        $this->assertTrue($patched);
        $this->assertTrue(strpos($content, '@ob_flush();') !== false);
        $this->assertTrue(strpos($content, "\nob_flush();\n") === false);

        @unlink($path);
    }

    public function testPatchReturnsTrueWhenRssFileAlreadyPatched(): void
    {
        $path = $this->tempPath('patch-already');
        file_put_contents($path, "before\n@ob_flush();\nafter\n");

        $patched = \pmssUserRutorrentRssObFlushPatchApply($path);
        $content = (string) file_get_contents($path);

        $this->assertTrue($patched);
        $this->assertTrue(strpos($content, '@ob_flush();') !== false);

        @unlink($path);
    }

    public function testPatchReturnsFalseWhenRssTargetMissing(): void
    {
        $this->assertTrue(!\pmssUserRutorrentRssObFlushPatchApply($this->tempPath('patch-missing')));
    }

    public function testPatchReturnsFalseForRssSymlinkPath(): void
    {
        $target = $this->tempPath('patch-symlink-target');
        $link = $this->tempPath('patch-symlink-link');
        file_put_contents($target, "before\nob_flush();\nafter\n");
        @symlink($target, $link);

        $this->assertTrue(!\pmssUserRutorrentRssObFlushPatchApply($link));

        @unlink($link);
        @unlink($target);
    }

    public function testPatchReturnsFalseWhenRssPatternMissing(): void
    {
        $path = $this->tempPath('patch-no-pattern');
        file_put_contents($path, "before\nflush();\nafter\n");

        $this->assertTrue(!\pmssUserRutorrentRssObFlushPatchApply($path));

        @unlink($path);
    }

    public function testMaintainCompatibilityTargetsRssActionPath(): void
    {
        $home = sys_get_temp_dir().'/pmss-rutorrent-rss-home-'.bin2hex(random_bytes(4));
        @mkdir($home.'/www/rutorrent/php', 0755, true);
        @mkdir($home.'/www/rutorrent/plugins/rss', 0755, true);

        $settingsPath = $home.'/www/rutorrent/php/settings.php';
        $rssPath = $home.'/www/rutorrent/plugins/rss/action.php';
        file_put_contents($settingsPath, "((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\n");
        file_put_contents($rssPath, "before\nob_flush();\nafter\n");

        try {
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);

            $settingsContent = (string) file_get_contents($settingsPath);
            $rssContent = (string) file_get_contents($rssPath);

            $this->assertTrue(strpos($settingsContent, '((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),') !== false);
            $this->assertTrue(strpos($rssContent, '@ob_flush();') !== false);
        } finally {
            $this->cleanup($home);
        }
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
