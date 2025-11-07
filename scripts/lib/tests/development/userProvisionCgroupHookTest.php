<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserProvisionCgroupHookTest extends TestCase
{
    public function testAddUserInvokesUserCgroupUtility(): void
    {
        $src = (string) file_get_contents('scripts/addUser.php');
        $this->assertTrue(strpos($src, '/scripts/util/userCgroup.php') !== false, 'addUser.php missing userCgroup hook');
        $this->assertTrue(strpos($src, '--apply --defaults') !== false, 'userCgroup hook should use --apply --defaults');
    }
}

