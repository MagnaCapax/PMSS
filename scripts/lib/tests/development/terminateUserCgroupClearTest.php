<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class TerminateUserCgroupClearTest extends TestCase
{
    public function testTerminateUserInvokesSystemdRevertOnSlice(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../terminateUser.php');
        $this->assertTrue(strpos($src, 'systemctl revert') !== false, 'terminateUser.php should revert user slice properties');
    }
}
