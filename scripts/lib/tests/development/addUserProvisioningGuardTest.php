<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

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
    }

    public function testAddUserWrapperStaysSmall(): void
    {
        $lines = file(__DIR__.'/../../../addUser.php', FILE_IGNORE_NEW_LINES);
        $this->assertTrue(is_array($lines), 'addUser.php must be readable');
        $this->assertTrue(count($lines) <= 200, 'addUser.php must stay under 200 lines');
    }
}
