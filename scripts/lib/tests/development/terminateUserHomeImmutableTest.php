<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class terminateUserHomeImmutableTest extends TestCase
{
    public function testTerminateUserClearsImmutableHomeBeforeRemovingIt(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../terminateUser.php');

        $posClear = strpos($src, "'clear_immutable_home'");
        $posRemove = strpos($src, "'remove_home_initial'");

        $this->assertTrue($posClear !== false, 'terminateUser.php should define a clear_immutable_home step');
        $this->assertTrue($posRemove !== false, 'terminateUser.php should define a remove_home_initial step');
        $this->assertTrue($posClear < $posRemove, 'terminateUser.php should clear immutable home files before removing the home directory');
        $this->assertStringContainsString('chattr -R -i', $src, 'terminateUser.php should clear immutable flags recursively from the user home');
        $this->assertStringContainsString('escapeshellarg("/home/{$username}")', $src, 'terminateUser.php should clear immutable flags from the canonical user home path');
    }
}
