<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserCrontabOwnershipTest extends TestCase
{
    public function testUpdateStep2DoesNotOverwriteUserCrontab(): void
    {
        $path = dirname(__DIR__, 2).'/update/userMaintenance.php';
        $src = (string) @file_get_contents($path);
        $this->assertTrue($src !== '', 'Expected to read userMaintenance.php');
        $this->assertTrue(strpos($src, 'user.crontab.default') === false, 'update-step2 must not reference user.crontab.default');
        $this->assertTrue(strpos($src, "pmssBuildCommand('crontab'") === false, 'update-step2 must not run crontab for users');
        $this->assertTrue(strpos($src, 'crontab -u') === false, 'update-step2 must not run crontab -u for users');
    }

    public function testAddUserDoesNotSeedUserCrontab(): void
    {
        $path = dirname(__DIR__, 2).'/user/add/postProvision.php';
        $src = (string) @file_get_contents($path);
        $this->assertTrue($src !== '', 'Expected to read postProvision.php');
        $this->assertTrue(strpos($src, 'user.crontab.default') === false, 'addUser post-provision must not seed user crontab');
        $this->assertTrue(strpos($src, 'crontab -u') === false, 'addUser post-provision must not run crontab -u');
    }
}

