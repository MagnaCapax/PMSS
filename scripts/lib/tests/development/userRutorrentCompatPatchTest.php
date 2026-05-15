<?php
namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/users.php';

class UserRutorrentCompatPatchTest extends TestCase
{
    public function testCompatibilityPatchesLegacyScheduleExpression(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/php');
        $path = $this->pmssWriteRelativeFile($home, 'www/rutorrent/php/settings.php', "prefix\n((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\nsuffix\n");

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $content = (string) file_get_contents($path);

        $this->assertTrue(strpos($content, '((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),') !== false);
        $this->assertTrue(strpos($content, '((integer)($tm["minutes"]/$interval))*$interval+$interval,') === false);
    }

    public function testCompatibilityLeavesPatchedScheduleExpressionUntouched(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/php');
        $path = $this->pmssWriteRelativeFile($home, 'www/rutorrent/php/settings.php', "prefix\n((integer)(\$tm[\"minutes\"]/((int)\$interval)))*((int)\$interval)+((int)\$interval),\nsuffix\n");

        $before = (string) file_get_contents($path);
        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertEquals($before, (string) file_get_contents($path));
    }

    public function testCompatibilitySkipsMissingSettingsTarget(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/php');

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertTrue(!file_exists($home.'/www/rutorrent/php/settings.php'));
    }

    public function testCompatibilitySkipsSymlinkedSettingsTarget(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/php');
        $target = $this->pmssMakeTempPhpPath('pmss-user-rutorrent-', 'symlink-target');
        $link = $home.'/www/rutorrent/php/settings.php';
        file_put_contents($target, "((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\n");
        @symlink($target, $link);

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertEquals("((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\n", (string) file_get_contents($target));
    }

    public function testCompatibilityLeavesNonMatchingSettingsContentUntouched(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/php');
        $path = $this->pmssWriteRelativeFile($home, 'www/rutorrent/php/settings.php', "prefix\n\$interval = \$interval * 60;\nsuffix\n");

        $before = (string) file_get_contents($path);
        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertEquals($before, (string) file_get_contents($path));
    }

    public function testCompatibilityPatchesAllKnownTargetsInSinglePass(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/php');
        $settingsPath = $this->pmssWriteRelativeFile($home, 'www/rutorrent/php/settings.php', "((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\n");
        $rssPath = $this->pmssWriteRelativeFile($home, 'www/rutorrent/plugins/rss/action.php', "ob_flush();\n");
        $hddquotaPath = $this->pmssWriteRelativeFile($home, 'www/rutorrent/plugins/hddquota/action.php', "return \$field;\n");

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);

        $this->assertStringContainsString('((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),', (string) file_get_contents($settingsPath));
        $this->assertStringContainsString('@ob_flush();', (string) file_get_contents($rssPath));
        $this->assertStringContainsString('return (int) $field;', (string) file_get_contents($hddquotaPath));
    }

}

}
