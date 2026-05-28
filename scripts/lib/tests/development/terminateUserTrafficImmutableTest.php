<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class TerminateUserTrafficImmutableTest extends TestCase
{
    public function testTerminateUserClearsImmutableTrafficBeforeHomeRemoval(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/terminateUser.php',
            ["'clear_immutable_traffic'", "'remove_home_initial'"],
            'terminateUser.php should define step ',
            'terminateUser.php should clear immutable traffic files before removing the home directory: '
        );
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/terminateUser.php',
            ['command -v chattr', 'array_values(pmssTrafficDataPaths($username))'],
            'terminateUser.php should keep immutable traffic handling: '
        );
    }
}
