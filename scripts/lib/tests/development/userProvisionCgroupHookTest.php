<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserProvisionCgroupHookTest extends TestCase
{
    public function testAddUserReliesOnUserConfigForCgroups(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/add/userConfigApply.php', '/scripts/util/userConfig.php', 'addUser must invoke userConfig.php');
        $this->pmssAssertRepoFileNotContainsString('scripts/addUser.php', 'userCgroup.php', 'addUser.php should not call userCgroup.php directly');
        $this->pmssAssertRepoFileNotContainsString('scripts/lib/user/add/userConfigApply.php', 'userCgroup.php', 'addUser helpers should not call userCgroup.php directly');
    }
}
