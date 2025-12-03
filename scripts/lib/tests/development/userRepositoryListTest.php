<?php
namespace PMSS\Tests;

// Match the on-disk path and class name for the filesystem helper; both
// file and class are named `userFilesystem` on case-sensitive systems.
require_once dirname(__DIR__, 2).'/user/userFilesystem.php';

class UserRepositoryListTest extends TestCase
{
    public function testListHomeUsers(): void
    {
        $users = \userFilesystem::listHomeUsers();
        $this->assertTrue(is_array($users));
    }
}
