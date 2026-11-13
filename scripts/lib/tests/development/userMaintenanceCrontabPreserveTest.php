<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserMaintenanceCrontabPreserveTest extends TestCase
{
    public function testUserMaintenanceDoesNotOverwriteUserCrontab(): void
    {
        $this->assertStringContainsAndOmitsStrings([], [
            'user.crontab.default',
            "pmssBuildCommand('crontab'",
            'Restoring default crontab',
            '$shouldRestoreCrontab',
        ], $this->pmssReadRepoFile('scripts/lib/update/userMaintenance.php'), 'userMaintenance.php must not carry user crontab overwrite logic: ');
    }
}
