<?php
namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/users.php';

class UserRutorrentCompatPatchTest extends TestCase
{
    public function testCompatibilityPatchesLegacyScheduleExpression(): void
    {
        $home = $this->pmssMakeUserHomeTree('pmss-rutorrent-home-', 'www/rutorrent/php');
        $path = $home.'/www/rutorrent/php/settings.php';
        file_put_contents($path, "prefix\n((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\nsuffix\n");

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $content = (string) file_get_contents($path);

        $this->assertTrue(strpos($content, '((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),') !== false);
        $this->assertTrue(strpos($content, '((integer)($tm["minutes"]/$interval))*$interval+$interval,') === false);
    }

    public function testCompatibilityLeavesPatchedScheduleExpressionUntouched(): void
    {
        $home = $this->pmssMakeUserHomeTree('pmss-rutorrent-home-', 'www/rutorrent/php');
        $path = $home.'/www/rutorrent/php/settings.php';
        file_put_contents($path, "prefix\n((integer)(\$tm[\"minutes\"]/((int)\$interval)))*((int)\$interval)+((int)\$interval),\nsuffix\n");

        $before = (string) file_get_contents($path);
        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertEquals($before, (string) file_get_contents($path));
    }

    public function testCompatibilitySkipsMissingSettingsTarget(): void
    {
        $home = $this->pmssMakeUserHomeTree('pmss-rutorrent-home-', 'www/rutorrent/php');

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertTrue(!file_exists($home.'/www/rutorrent/php/settings.php'));
    }

    public function testCompatibilitySkipsSymlinkedSettingsTarget(): void
    {
        $home = $this->pmssMakeUserHomeTree('pmss-rutorrent-home-', 'www/rutorrent/php');
        $target = $this->pmssMakeTempPhpPath('pmss-user-rutorrent-', 'symlink-target');
        $link = $home.'/www/rutorrent/php/settings.php';
        file_put_contents($target, "((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\n");
        @symlink($target, $link);

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertEquals("((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\n", (string) file_get_contents($target));
    }

    public function testCompatibilityLeavesNonMatchingSettingsContentUntouched(): void
    {
        $home = $this->pmssMakeUserHomeTree('pmss-rutorrent-home-', 'www/rutorrent/php');
        $path = $home.'/www/rutorrent/php/settings.php';
        file_put_contents($path, "prefix\n\$interval = \$interval * 60;\nsuffix\n");

        $before = (string) file_get_contents($path);
        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertEquals($before, (string) file_get_contents($path));
    }

    public function testCompatibilityPatchesAllKnownTargetsInSinglePass(): void
    {
        $home = $this->pmssMakeUserHomeTree('pmss-rutorrent-home-', 'www/rutorrent/php');
        mkdir($home.'/www/rutorrent/plugins/rss', 0755, true);
        mkdir($home.'/www/rutorrent/plugins/hddquota', 0755, true);

        $settingsPath = $home.'/www/rutorrent/php/settings.php';
        $rssPath = $home.'/www/rutorrent/plugins/rss/action.php';
        $hddquotaPath = $home.'/www/rutorrent/plugins/hddquota/action.php';

        file_put_contents($settingsPath, "((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\n");
        file_put_contents($rssPath, "ob_flush();\n");
        file_put_contents($hddquotaPath, "return \$field;\n");

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);

        $this->assertStringContainsString('((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),', (string) file_get_contents($settingsPath));
        $this->assertStringContainsString('@ob_flush();', (string) file_get_contents($rssPath));
        $this->assertStringContainsString('return (int) $field;', (string) file_get_contents($hddquotaPath));
    }

}

}
