<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CustomerPanelRenderHarnessTest extends TestCase
{
    public function testRendersCurrentCustomerPanelWithoutPhpErrors(): void
    {
        $run = $this->pmssCustomerPanelRenderHarnessRun();

        $this->assertSame(0, $run['result']['rc']);
        $this->assertStringContainsString('[customer-panel-render-harness] OK', $run['stderr']);
        $payload = $this->pmssDecodeJsonArray($run['result']['output']);
        $this->assertSame(true, $payload['ok']);
        $this->assertSame(3, count($payload['pages']));
        $this->assertFalse(is_dir($payload['environmentRoot']), 'Harness must clean its synthetic customer home');
    }

    public function testReportsUndefinedFunctionFatalFromFixture(): void
    {
        $root = $this->pmssMakeTempDir('pmss-render-fixture-', 0700);
        $this->pmssWriteRelativeFile($root, 'etc/skel/www/welcome.php', "<?php\npmssMissingCustomerHelper();\n");
        $this->pmssWriteRelativeFile($root, 'etc/skel/www/info.php', "<?php\necho str_repeat('i', 16);\n");
        $this->pmssWriteRelativeFile($root, 'etc/skel/www/stats.php', "<?php\necho str_repeat('s', 16);\n");

        $run = $this->pmssCustomerPanelRenderHarnessRun([
            'PMSS_CUSTOMER_PANEL_RENDER_ROOT' => $root,
            'PMSS_CUSTOMER_PANEL_RENDER_MIN_BYTES' => '1',
            'PMSS_CUSTOMER_PANEL_RENDER_DISABLE_MARKERS' => '1',
        ]);

        $this->assertSame(1, $run['result']['rc']);
        $this->assertStringContainsString('[customer-panel-render-harness] FAIL', $run['stderr']);
        $this->assertStringContainsString('Call to undefined function', $run['stderr']);
        $payload = $this->pmssDecodeJsonArray($run['result']['output']);
        $this->assertSame(false, $payload['ok']);
        $this->assertFalse(is_dir($payload['environmentRoot']), 'Harness must clean failed synthetic customer homes');
    }

    private function pmssCustomerPanelRenderHarnessRun(array $environment = []): array
    {
        $run = $this->pmssRunRepoPhpScriptCommandWithTempStderr(
            'scripts/testing/customer-panel-render-harness.php',
            [],
            $environment,
            'pmss-render-stderr-'
        );
        $run['stderr'] = (string) @file_get_contents($run['stderrPath']);
        return $run;
    }
}
