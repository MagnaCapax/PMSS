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
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/userMaintenance.php', [
            "require_once __DIR__.'/users/docker.php';",
            'Processed %d of %d users',
            "'empty username entry' : 'invalid username'",
            "Account '%s' skipped during environment refresh: %s",
            "'event'     => 'user_maintenance_summary'",
            "'processed' => ", "'skipped' => ",
            'pmssUserMaintenanceAccountRefresh(',
        ]);
        $this->pmssAssertRepoFileContract('scripts/lib/update/users/maintenanceAccount.php', [
            'required' => [
                '$environmentReason = null;',
                "if (!pmssUpdateUserEnvironment(\$userTrim, \$rutorrentIndexSha, \$environmentReason)) {",
                "\$environmentMessage = 'user environment convergence failed';",
                "\$environmentMessage .= ': '.\$environmentReason;",
                "throw new RuntimeException(\$environmentMessage);",
            ],
            'ordered' => [[
                'needles' => [
                    "if (!pmssUpdateUserEnvironment(\$userTrim, \$rutorrentIndexSha, \$environmentReason)) {",
                    'pmssUserRefreshMarkDone($userTrim, $refreshSignature);',
                ],
                'missingPrefix' => 'Missing convergence/marker phase: ',
                'orderPrefix' => 'Convergence marker order changed at: ',
            ]],
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/docker.php', [
            "pmssUserMaintenanceUsernameAllowed(\$user, 'pmssEnsureLingerAndDocker')",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/dockerCommands.php', [
            "pmssUserMaintenanceUsernameAllowed(\$user, 'pmssRunAndLog')",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/dockerInstall.php', [
            "pmssUserMaintenanceUsernameAllowed(\$user, 'pmssEnsureRootlessDockerInstalled')",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/dockerDependencies.php', [
            "pmssUserMaintenanceUsernameAllowed(\$user, 'pmssEnsureDockerDependencies')",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/update-step2.php', [
            '$userMaintenanceSummary = pmssRunProfiledCallable(\'Updating all user environments\'',
            'pmssUpdateStep2HandleUserMaintenanceSummary($userMaintenanceSummary);',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/permissions.php', [
            'PMSS_USER_PERMISSIONS_TIMEOUT', 'PMSS_COMMAND_TIMEOUT', "'-c3'", 'RuntimeException',
        ]);
    }
}
