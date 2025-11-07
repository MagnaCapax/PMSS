<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/UserFilesystem.php';

class UserRepositoryListTest extends TestCase
{
    public function testListHomeUsers(): void
    {
        $users = \UserFilesystem::listHomeUsers();
        $this->assertTrue(is_array($users));
    }
}
