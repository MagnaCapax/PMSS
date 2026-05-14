<?php
namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/users.php';

class UserRutorrentRssCompatPatchTest extends TestCase
{
    public function testCompatibilityPatchesLegacyRssObFlushCall(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/plugins/rss');
        $path = $home.'/www/rutorrent/plugins/rss/action.php';
        file_put_contents($path, "before\nob_flush();\nafter\n");

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $content = (string) file_get_contents($path);

        $this->assertTrue(strpos($content, '@ob_flush();') !== false);
        $this->assertTrue(strpos($content, "\nob_flush();\n") === false);
    }

    public function testCompatibilityLeavesPatchedRssObFlushCallUntouched(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/plugins/rss');
        $path = $home.'/www/rutorrent/plugins/rss/action.php';
        file_put_contents($path, "before\n@ob_flush();\nafter\n");

        $before = (string) file_get_contents($path);
        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertEquals($before, (string) file_get_contents($path));
    }

    public function testCompatibilitySkipsMissingRssTarget(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/plugins/rss');

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertTrue(!file_exists($home.'/www/rutorrent/plugins/rss/action.php'));
    }

    public function testCompatibilitySkipsSymlinkedRssTarget(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/plugins/rss');
        $target = $this->pmssMakeTempPhpPath('pmss-user-rutorrent-rss-', 'patch-symlink-target');
        $link = $home.'/www/rutorrent/plugins/rss/action.php';
        file_put_contents($target, "before\nob_flush();\nafter\n");
        @symlink($target, $link);

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertEquals("before\nob_flush();\nafter\n", (string) file_get_contents($target));
    }

    public function testCompatibilityLeavesNonMatchingRssContentUntouched(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/plugins/rss');
        $path = $home.'/www/rutorrent/plugins/rss/action.php';
        file_put_contents($path, "before\nflush();\nafter\n");

        $before = (string) file_get_contents($path);
        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertEquals($before, (string) file_get_contents($path));
    }

}

}
