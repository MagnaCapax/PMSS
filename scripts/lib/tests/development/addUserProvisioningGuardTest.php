<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/add/provisioningRuntime.php';

class AddUserProvisioningGuardTest extends TestCase
{
    public function testAddUserUsesPerUserLock(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../addUser.php');
        $this->assertTrue(strpos($src, 'pmss-addUser-') !== false, 'addUser.php must use per-user lock file');
        $this->assertTrue(strpos($src, 'flock(') !== false, 'addUser.php must acquire a lock');
    }

    public function testAddUserEmitsSummaryMarker(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../user/add/provisioningRuntime.php');
        $this->assertTrue(strpos($src, '###ADDUSER:') !== false, 'addUser must emit summary markers');
        $this->assertTrue(strpos($src, '###ADDUSER_JSON:') !== false, 'addUser must emit JSON summary markers');
    }

    public function testAddUserRuntimeInitStaysWithProvisioningRuntimeHelpers(): void
    {
        $this->assertTrue(function_exists('\pmssAddUserRuntimeInit'));
        $src = (string) file_get_contents(__DIR__.'/../../user/add/provisioningRuntime.php');
        $this->assertTrue(strpos($src, 'function pmssAddUserRuntimeInit(') !== false);
    }

    public function testAddUserWrapperStaysSmall(): void
    {
        $lines = file(__DIR__.'/../../../addUser.php', FILE_IGNORE_NEW_LINES);
        $this->assertTrue(is_array($lines), 'addUser.php must be readable');
        $this->assertTrue(count($lines) <= 200, 'addUser.php must stay under 200 lines');
    }

    public function testAddUserStillStartsServicesAndRefreshesNetwork(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../addUser.php');
        $rtorrentPos = strpos($src, '/scripts/startRtorrent');
        $lighttpdPos = strpos($src, '/scripts/startLighttpd');
        $networkPos = strpos($src, '/scripts/util/setupNetwork.php');

        $this->assertTrue($rtorrentPos !== false, 'addUser.php must start rTorrent');
        $this->assertTrue($lighttpdPos !== false, 'addUser.php must start lighttpd');
        $this->assertTrue($networkPos !== false, 'addUser.php must refresh network rules');
        $this->assertTrue($rtorrentPos < $lighttpdPos, 'addUser.php must start rTorrent before lighttpd');
        $this->assertTrue($lighttpdPos < $networkPos, 'addUser.php must refresh network after starting services');
    }

    public function testAddUserRefreshesPatchedTorrentFrontendsBeforeServices(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../addUser.php');
        $patchPos = strpos($src, "pmssUserApplySkeletonFiles(['user' => ");
        $lighttpdPos = strpos($src, '/scripts/startLighttpd');

        $this->assertTrue(strpos($src, "require_once 'lib/update.php';") !== false);
        $this->assertTrue(strpos($src, "require_once 'lib/update/users/filesystem.php';") !== false);
        $this->assertTrue($patchPos !== false, 'addUser.php must refresh skeleton frontends after user config');
        $this->assertTrue($lighttpdPos !== false, 'addUser.php must still start lighttpd');
        $this->assertTrue($patchPos < $lighttpdPos, 'addUser.php must patch torrent frontends before services start');
    }
}
