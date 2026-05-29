<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class TerminateUserContractTest extends TestCase
{
    public function testTerminateUserConfirmationLoopHandlesEof(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/terminateUser.php',
            ['confirmation input unavailable (EOF)', 'Unable to read confirmation input (EOF)'],
            'terminateUser.php should handle EOF confirmation: '
        );
    }

    public function testTerminateUserInvokesSystemdRevertOnSlice(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'scripts/terminateUser.php',
            'systemctl revert',
            'terminateUser.php should revert user slice properties'
        );
    }

    public function testTerminateUserClearsCrontabBeforeUserdel(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/terminateUser.php',
            ["'crontab_remove'", "'userdel_initial'"],
            'terminateUser.php should define step ',
            'terminateUser.php should clear crontab before deleting the user account: '
        );
    }

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

    public function testTerminateUserClearsImmutableHomeBeforeRemovingIt(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/terminateUser.php',
            ["'clear_immutable_home'", "'remove_home_initial'"],
            'terminateUser.php should define step ',
            'terminateUser.php should clear immutable home files before removing the home directory: '
        );
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/terminateUser.php',
            ['chattr -R -i', 'escapeshellarg("/home/{$username}")'],
            'terminateUser.php should keep immutable home handling: '
        );
    }
}
