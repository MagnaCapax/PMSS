<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class TerminateUserCgroupClearTest extends TestCase
{
    public function testTerminateUserInvokesSystemdRevertOnSlice(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/terminateUser.php', 'systemctl revert', 'terminateUser.php should revert user slice properties');
    }
}
