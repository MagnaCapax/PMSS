<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserProvisionCgroupHookTest extends TestCase
{
    public function testAddUserReliesOnUserConfigForCgroups(): void
    {
        $src = (string) file_get_contents('scripts/addUser.php');
        $this->assertTrue(strpos($src, '/scripts/util/userConfig.php') !== false, 'addUser.php must invoke userConfig.php');
        $this->assertTrue(strpos($src, 'userCgroup.php') === false, 'addUser.php should not call userCgroup.php directly');
    }
}
