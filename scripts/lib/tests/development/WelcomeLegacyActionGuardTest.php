<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Legacy Welcome mutations share the panel's POST/XHR contract. */
class WelcomeLegacyActionGuardTest extends TestCase
{
    public function testRestoreAndRotateCallGuardBeforeMutation(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $restore = substr($source, strpos($source, "\$_GET['restore'] === 'config'"), 450);
        $rotate = substr($source, strpos($source, "isset(\$_POST['delugePasswordRotate'])"), 250);
        $this->assertTrue(strpos($restore, 'pmssFrontendPostActionRequired();') < strpos($restore, 'pmssCustomerBackupRestore('));
        $this->assertTrue(strpos($rotate, 'pmssFrontendPostActionRequired();') < strpos($rotate, 'pmssDelugeServicePasswordRotate('));
        $this->assertStringContainsString('Use the restore form to choose an uploaded backup archive.', $restore);
    }

    public function testLegacyFormsSendRequestedWithHeader(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $this->assertStringContainsString("headers: {'X-Requested-With': 'XMLHttpRequest'}", $source);
        $this->assertStringContainsString('name="delugePasswordRotate"', $source);
        $this->assertSame(2, substr_count($source, 'onsubmit="return pmssWelcomeActionSubmit(this)"'));
    }

    public function testHeaderlessPostStopsBeforeMutationAndHeaderedPostContinues(): void
    {
        $guard = $this->pmssRepoPath('etc/skel/www/scriptsIncFrontend.php');
        $sentinel = $this->pmssMakeTempDir('pmss-welcome-guard-').'/mutation';
        foreach (['GET', 'POST', 'POST-XHR'] as $case) {
            @unlink($sentinel);
            $script = <<<'PHP'
$_SERVER = ['REQUEST_METHOD' => %s];
if (%s === 'POST-XHR') $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
require %s;
http_response_code(200);
register_shutdown_function(function () { echo json_encode([http_response_code(), file_exists(%s)]); });
pmssFrontendPostActionRequired();
file_put_contents(%s, 'changed');
PHP;
            $run = $this->pmssExecInlinePhp(sprintf(
                $script, var_export($case === 'POST-XHR' ? 'POST' : $case, true), var_export($case, true),
                var_export($guard, true), var_export($sentinel, true), var_export($sentinel, true)
            ));
            $this->assertSame(0, $run['rc']);
            $result = json_decode($run['output'], true);
            $this->assertSame($case === 'GET' ? 405 : ($case === 'POST' ? 403 : 200), $result[0]);
            $this->assertSame($case === 'POST-XHR', $result[1]);
        }
    }
}
