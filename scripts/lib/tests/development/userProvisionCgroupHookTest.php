<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserProvisionCgroupHookTest extends TestCase
{
    public function testAddUserReliesOnUserConfigForCgroups(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../user/add/userConfigApply.php');
        $this->assertTrue(strpos($src, '/scripts/util/userConfig.php') !== false, 'addUser must invoke userConfig.php');

        $entrypoint = (string) file_get_contents(__DIR__.'/../../../addUser.php');
        $this->assertTrue(strpos($entrypoint, 'userCgroup.php') === false, 'addUser.php should not call userCgroup.php directly');
        $this->assertTrue(strpos($src, 'userCgroup.php') === false, 'addUser helpers should not call userCgroup.php directly');
    }
}
