<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserMaintenancePartialCompletionGuardTest extends TestCase
{
    public function testRunAndLogRejectsInvalidUsernameBeforeShellExecution(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $marker = $this->pmssMakeTempPath('pmss-user-maintenance-marker-');
        $script = sprintf(
            <<<'PHP'
$repoRoot = %s;
$marker = %s;
putenv('PMSS_TEST_MODE=1');
require $repoRoot.'/scripts/lib/update/userMaintenance.php';
ob_start();
$rc = pmssRunAndLog("bad;name", 'boundary guard', 'printf ran > '.escapeshellarg($marker));
$output = ob_get_clean();
echo json_encode([
    'rc' => $rc,
    'marker_exists' => file_exists($marker),
    'output' => $output,
]);
PHP,
            var_export($repoRoot, true),
            var_export($marker, true)
        );

        $result = $this->pmssRunInlinePhpJson($script);

        $this->assertEquals(127, $result['rc']);
        $this->assertFalse($result['marker_exists']);
        $this->assertStringContainsString('pmssRunAndLog refused invalid username', $result['output']);
    }

    public function testUserMaintenanceShellBoundaryHelpersShareUsernameGuard(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/userMaintenance.php');

        foreach ([
            'pmssRunAndLog',
            'pmssEnsureLingerAndDocker',
            'pmssEnsureRootlessDockerInstalled',
            'pmssEnsureDockerDependencies',
        ] as $functionName) {
            $this->assertStringContainsString(
                "pmssUserMaintenanceUsernameAllowed(\$user, '{$functionName}')",
                $src,
                'Expected username guard in '.$functionName
            );
        }
    }

    public function testUserMaintenanceEmitsProcessedSummaryAndJsonEvent(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/userMaintenance.php');

        $this->assertStringContainsString('Processed %d of %d users', $src);
        $this->assertStringContainsString("Account '(empty)' skipped during environment refresh: empty username entry", $src);
        $this->assertStringContainsString("Account '%s' skipped during environment refresh: invalid username", $src);
        $this->assertStringContainsString("Account '%s' skipped during environment refresh: %s", $src);
        $this->assertStringContainsString("'event'     => 'user_maintenance_summary'", $src);
        $this->assertStringContainsString("'processed' => ", $src);
        $this->assertStringContainsString("'skipped'   => ", $src);
    }

    public function testUpdateStep2FailsWhenUserProcessingIsPartial(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $this->assertStringContainsString('processed_users_mismatch', $src);
        $this->assertStringContainsString('PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED', $src);
        $this->assertStringContainsString('$processedUsers < $totalUsers', $src);
    }

    public function testUserPermissionsRefreshUsesScopedTimeoutAndIonice(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/users/permissions.php');

        $this->assertStringContainsString('PMSS_USER_PERMISSIONS_TIMEOUT', $src);
        $this->assertStringContainsString('PMSS_COMMAND_TIMEOUT', $src);
        $this->assertStringContainsString("'-c3'", $src);
        $this->assertStringContainsString('RuntimeException', $src);
    }
}
