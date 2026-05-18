<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserMaintenanceCrontabPreserveTest extends TestCase
{
    public function testUserMaintenanceDoesNotOverwriteUserCrontab(): void
    {
        $this->pmssAssertRepoFileNotContainsStrings('scripts/lib/update/userMaintenance.php', [
            'user.crontab.default',
            "pmssBuildCommand('crontab'",
            'Restoring default crontab',
            '$shouldRestoreCrontab',
        ], 'userMaintenance.php must not carry user crontab overwrite logic: ');
    }
}
