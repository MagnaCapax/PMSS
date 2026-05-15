<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class TerminateUserTrafficImmutableTest extends TestCase
{
    public function testTerminateUserClearsImmutableTrafficBeforeHomeRemoval(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../terminateUser.php');
        $this->assertOrderedStrings(
            ["'clear_immutable_traffic'", "'remove_home_initial'"],
            $src,
            'terminateUser.php should define step ',
            'terminateUser.php should clear immutable traffic files before removing the home directory: '
        );
        $this->assertStringContainsString('command -v chattr', $src, 'terminateUser.php should guard immutable clearing with a chattr presence check');
        $this->assertStringContainsString('array_values(pmssTrafficDataPaths($username))', $src, 'terminateUser.php should source all traffic files from the shared helper');
    }
}
