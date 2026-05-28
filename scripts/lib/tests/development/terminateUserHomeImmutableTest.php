<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class terminateUserHomeImmutableTest extends TestCase
{
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
