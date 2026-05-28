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
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/userMaintenance.php', [
            'Processed %d of %d users',
            "Account '(empty)' skipped during environment refresh: empty username entry",
            "Account '%s' skipped during environment refresh: invalid username",
            "Account '%s' skipped during environment refresh: %s",
            "'event'     => 'user_maintenance_summary'",
            "'processed' => ",
            "'skipped'   => ",
        ]);
    }

    public function testUpdateStep2DelegatesPartialUserProcessingPolicy(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/update-step2.php', [
            '$userMaintenanceSummary = pmssRunProfiledCallable(\'Updating all user environments\'',
            'pmssUpdateStep2HandleUserMaintenanceSummary($userMaintenanceSummary);',
        ]);
    }

    public function testUserPermissionsRefreshUsesScopedTimeoutAndIonice(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/permissions.php', [
            'PMSS_USER_PERMISSIONS_TIMEOUT',
            'PMSS_COMMAND_TIMEOUT',
            "'-c3'",
            'RuntimeException',
        ]);
    }
}
