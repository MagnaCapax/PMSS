<?php
namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/users.php';

class UserRutorrentHddquotaCompatPatchTest extends TestCase
{
    public function testCompatibilityPatchesLegacyHddquotaReturnCast(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/plugins/hddquota');
        $path = $this->pmssWriteRelativeFile($home, 'www/rutorrent/plugins/hddquota/action.php', "prefix\n        return \$field;\nsuffix\n");

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('return (int) $field;', $content);
        $this->pmssAssertStringNotContainsString('return $field;', $content);
    }

    public function testCompatibilityLeavesPatchedHddquotaReturnUntouched(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/plugins/hddquota');
        $path = $this->pmssWriteRelativeFile($home, 'www/rutorrent/plugins/hddquota/action.php', "prefix\n        return (int) \$field;\nsuffix\n");

        $before = (string) file_get_contents($path);
        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertEquals($before, (string) file_get_contents($path));
    }

    public function testCompatibilitySkipsMissingHddquotaTarget(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/plugins/hddquota');

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertTrue(!file_exists($home.'/www/rutorrent/plugins/hddquota/action.php'));
    }

    public function testCompatibilitySkipsSymlinkedHddquotaTarget(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/plugins/hddquota');
        $target = $this->pmssMakeTempPhpPath('pmss-user-rutorrent-', 'symlink-target');
        $link = $home.'/www/rutorrent/plugins/hddquota/action.php';
        file_put_contents($target, "return \$field;\n");
        @symlink($target, $link);

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertEquals("return \$field;\n", (string) file_get_contents($target));
    }

    public function testSkeletonHddquotaActionReturnsInt(): void
    {
        $path = dirname(__DIR__, 4).'/etc/skel/www/rutorrent/plugins/hddquota/action.php';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('return (int) $field;', $content);
    }

}

}
