<?php
namespace PMSS\Tests {

require_once __DIR__.'/../common/FilesystemCleanupTrait.php';
require_once dirname(__DIR__, 2).'/update/users.php';

class UserRutorrentCompatPatchTest extends TestCase
{
    use FilesystemCleanupTrait;

    public function testCompatibilityPatchesLegacyScheduleExpression(): void
    {
        $home = $this->createHome();
        $path = $home.'/www/rutorrent/php/settings.php';
        file_put_contents($path, "prefix\n((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\nsuffix\n");

        try {
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $content = (string) file_get_contents($path);

            $this->assertTrue(strpos($content, '((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),') !== false);
            $this->assertTrue(strpos($content, '((integer)($tm["minutes"]/$interval))*$interval+$interval,') === false);
        } finally {
            $this->cleanup($home);
        }
    }

    public function testCompatibilityLeavesPatchedScheduleExpressionUntouched(): void
    {
        $home = $this->createHome();
        $path = $home.'/www/rutorrent/php/settings.php';
        file_put_contents($path, "prefix\n((integer)(\$tm[\"minutes\"]/((int)\$interval)))*((int)\$interval)+((int)\$interval),\nsuffix\n");

        try {
            $before = (string) file_get_contents($path);
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $this->assertEquals($before, (string) file_get_contents($path));
        } finally {
            $this->cleanup($home);
        }
    }

    public function testCompatibilitySkipsMissingSettingsTarget(): void
    {
        $home = $this->createHome();

        try {
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $this->assertTrue(!file_exists($home.'/www/rutorrent/php/settings.php'));
        } finally {
            $this->cleanup($home);
        }
    }

    public function testCompatibilitySkipsSymlinkedSettingsTarget(): void
    {
        $home = $this->createHome();
        $target = $this->tempPath('symlink-target');
        $link = $home.'/www/rutorrent/php/settings.php';
        file_put_contents($target, "((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\n");
        @symlink($target, $link);

        try {
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $this->assertEquals("((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\n", (string) file_get_contents($target));
        } finally {
            @unlink($target);
            $this->cleanup($home);
        }
    }

    public function testCompatibilityLeavesNonMatchingSettingsContentUntouched(): void
    {
        $home = $this->createHome();
        $path = $home.'/www/rutorrent/php/settings.php';
        file_put_contents($path, "prefix\n\$interval = \$interval * 60;\nsuffix\n");

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
        $home = sys_get_temp_dir().'/pmss-rutorrent-home-'.bin2hex(random_bytes(4));
        @mkdir($home.'/www/rutorrent/php', 0755, true);
        return $home;
    }

    private function tempPath(string $suffix): string
    {
        return sys_get_temp_dir().'/pmss-user-rutorrent-'.$suffix.'-'.bin2hex(random_bytes(4)).'.php';
    }

}

}
