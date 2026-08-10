<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserMaintenancePartialCompletionGuardTest extends TestCase
{
    public function testRunAndLogRejectsInvalidUsernameBeforeShellExecution(): void
    {
        $repoRoot = $this->pmssRepoRoot();
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

    public function testUserMaintenanceSourceContractsStayWired(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/update/userMaintenance.php' => [
                'required' => [
                    "require_once __DIR__.'/users/docker.php';",
                    'Processed %d of %d users',
                    "Account '(empty)' skipped during environment refresh: empty username entry",
                    "Account '%s' skipped during environment refresh: invalid username",
                    "Account '%s' skipped during environment refresh: %s",
                    "if (!pmssUpdateUserEnvironment(\$userTrim, \$rutorrentIndexSha)) {",
                    "throw new RuntimeException('user environment convergence failed');",
                    "'event'     => 'user_maintenance_summary'",
                    "'processed' => ",
                    "'skipped'   => ",
                ],
                'ordered' => [[
                    'needles' => [
                        "if (!pmssUpdateUserEnvironment(\$userTrim, \$rutorrentIndexSha)) {",
                        'pmssUserRefreshMarkDone($userTrim, $refreshSignature);',
                    ],
                    'missingPrefix' => 'Missing convergence/marker phase: ',
                    'orderPrefix' => 'Convergence marker order changed at: ',
                ]],
            ],
            'scripts/lib/update/users/docker.php' => [
                'required' => [
                    "pmssUserMaintenanceUsernameAllowed(\$user, 'pmssRunAndLog')",
                    "pmssUserMaintenanceUsernameAllowed(\$user, 'pmssEnsureLingerAndDocker')",
                    "pmssUserMaintenanceUsernameAllowed(\$user, 'pmssEnsureRootlessDockerInstalled')",
                    "pmssUserMaintenanceUsernameAllowed(\$user, 'pmssEnsureDockerDependencies')",
                ],
            ],
            'scripts/util/update-step2.php' => [
                'required' => [
                    '$userMaintenanceSummary = pmssRunProfiledCallable(\'Updating all user environments\'',
                    'pmssUpdateStep2HandleUserMaintenanceSummary($userMaintenanceSummary);',
                ],
            ],
            'scripts/lib/update/users/permissions.php' => [
                'required' => ['PMSS_USER_PERMISSIONS_TIMEOUT', 'PMSS_COMMAND_TIMEOUT', "'-c3'", 'RuntimeException'],
            ],
        ]);
    }
}
