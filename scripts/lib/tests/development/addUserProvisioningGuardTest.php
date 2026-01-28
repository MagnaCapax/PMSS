<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class AddUserProvisioningGuardTest extends TestCase
{
    public function testAddUserUsesPerUserLock(): void
    {
        $src = (string) file_get_contents('scripts/addUser.php');
        $this->assertTrue(strpos($src, 'pmss-addUser-') !== false, 'addUser.php must use per-user lock file');
        $this->assertTrue(strpos($src, 'flock(') !== false, 'addUser.php must acquire a lock');
    }

    public function testAddUserEmitsSummaryMarker(): void
    {
        $src = (string) file_get_contents('scripts/addUser.php');
        $this->assertTrue(strpos($src, '###ADDUSER:') !== false, 'addUser.php must emit summary markers');
    }
}
