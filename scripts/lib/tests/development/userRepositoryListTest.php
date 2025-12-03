<?php
namespace PMSS\Tests;

// Match the on-disk path for the filesystem helper; the file name is
// `userFilesystem.php` even though the class is `UserFilesystem`.
require_once dirname(__DIR__, 2).'/user/userFilesystem.php';

class UserRepositoryListTest extends TestCase
{
    public function testListHomeUsers(): void
    {
        $users = \UserFilesystem::listHomeUsers();
        $this->assertTrue(is_array($users));
    }
}
