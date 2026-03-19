<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserMaintenancePartialCompletionGuardTest extends TestCase
{
    public function testUserMaintenanceEmitsProcessedSummaryAndJsonEvent(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/userMaintenance.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertStringContainsString('Processed %d of %d users', $src);
        $this->assertStringContainsString("'event'     => 'user_maintenance_summary'", $src);
        $this->assertStringContainsString("'processed' => ", $src);
        $this->assertStringContainsString("'skipped'   => ", $src);
    }

    public function testUpdateStep2FailsWhenUserProcessingIsPartial(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/util/update-step2.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertStringContainsString('processed_users_mismatch', $src);
        $this->assertStringContainsString('PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED', $src);
        $this->assertStringContainsString('$processedUsers < $totalUsers', $src);
    }

    public function testUserPermissionsRefreshUsesScopedTimeoutAndIonice(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/users/filesystem.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertStringContainsString('PMSS_USER_PERMISSIONS_TIMEOUT', $src);
        $this->assertStringContainsString('PMSS_COMMAND_TIMEOUT', $src);
        $this->assertStringContainsString("'-c3'", $src);
        $this->assertStringContainsString('RuntimeException', $src);
    }
}
