<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserCrontabOwnershipTest extends TestCase
{
    public function testUpdateStep2DoesNotOverwriteUserCrontab(): void
    {
        $this->pmssAssertRepoFileContract('scripts/lib/update/userMaintenance.php', ['forbidden' => [
            'user.crontab.default' => 'update-step2 must not reference user.crontab.default',
            "pmssBuildCommand('crontab'" => 'update-step2 must not run crontab for users',
            'crontab -u' => 'update-step2 must not run crontab -u for users',
        ]]);
    }

    public function testAddUserDoesNotSeedUserCrontab(): void
    {
        $this->pmssAssertRepoFileContract('scripts/lib/user/add/postProvision.php', ['forbidden' => [
            'user.crontab.default' => 'addUser post-provision must not seed user crontab',
            'crontab -u' => 'addUser post-provision must not run crontab -u',
        ]]);
    }
}

