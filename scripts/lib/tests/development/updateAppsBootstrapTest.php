<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateAppsBootstrapTest extends TestCase
{
    public function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-update-app-bootstrap', 0700);
    }

    private function appBootstrapOutput(string $installerFile): string
    {
        $sourceDir = dirname(__DIR__, 2).'/update/apps';
        $sandboxDir = $this->tempDir.'/apps-'.basename($installerFile, '.php');
        $this->assertTrue(@mkdir($sandboxDir, 0700, true), 'Unable to create sandbox app dir');

        foreach ([$installerFile, 'bootstrap.php', 'arr.php'] as $file) {
            $sourcePath = $sourceDir.'/'.$file;
            if (!is_file($sourcePath)) {
                continue;
            }

            $targetPath = $sandboxDir.'/'.$file;
            $this->assertTrue(@copy($sourcePath, $targetPath), 'Unable to copy '.$file.' into sandbox');
        }

        $output = $this->pmssRunInlinePhp('include '.var_export($sandboxDir.'/'.$installerFile, true).';', [], '2>&1');
        $this->assertTrue(is_string($output), 'Unable to execute sandboxed installer');

        return $output;
    }

    public function testServarrInstallerWarnsForEveryAppWhenRuntimeHelperMissing(): void
    {
        $output = $this->appBootstrapOutput('servarr.php');

        foreach (['Lidarr', 'Prowlarr', 'Radarr', 'Readarr', 'Sonarr'] as $label) {
            $this->assertStringContainsString($label.' updater: missing runtime helper', $output);
        }
        $this->pmssAssertStringNotContainsString('Fatal error', $output, 'Servarr bootstrap should soft-return when runtime is missing');
    }

    public function testUpdateStep2SkipsHelperModulesInAppLoader(): void
    {
        $contents = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $this->assertStringContainsAllStrings(["'arr.php'", "'pythonVenv.php'", "'remoteBinary.php'"], $contents);
        $this->pmssAssertStringNotContainsString("'packages.php'", $contents, 'retired package module should not remain in app loader skip list');
    }

    public function testPythonVenvInstallersAvoidPackageQueueHelpers(): void
    {
        foreach (['python.php', 'pyload.php'] as $installer) {
            $contents = $this->pmssReadUpdateAppFile($installer);

            $this->assertStringContainsString("require_once __DIR__.'/pythonVenv.php';", $contents);
            $this->pmssAssertStringNotContainsString('packageState.php', $contents, $installer.' should not pull package-state helpers when it only needs the shared venv runtime');
            $this->pmssAssertStringNotContainsString('packages/helpers.php', $contents, $installer.' should not pull package-state helpers when it only needs the shared venv runtime');
        }
    }

    public function testArrHelperKeepsSharedRuntimeBootstrapPath(): void
    {
        $contents = $this->pmssReadUpdateAppFile('arr.php');

        $this->assertStringContainsAllStrings(["dirname(__DIR__, 2).'/runtime.php'", '%s updater: missing runtime helper'], $contents);
        $this->pmssAssertStringNotContainsString("require_once __DIR__.'/bootstrap.php';", $contents, 'ARR helper should not require a separate bootstrap helper');
    }

    public function testServarrInstallerDelegatesRuntimeBootstrapToArrHelper(): void
    {
        $contents = $this->pmssReadUpdateAppFile('servarr.php');

        $this->assertStringContainsAllStrings(["require_once __DIR__.'/arr.php';", 'pmssArrUpdateSupportedApps();'], $contents);
        $this->pmssAssertStringNotContainsString("dirname(__DIR__).'/runtime.php'", $contents, 'servarr.php should delegate runtime bootstrap to arr.php');
        $this->pmssAssertStringNotContainsString('missing runtime helper', $contents, 'servarr.php should keep the runtime warning in arr.php');
        $this->pmssAssertStringNotContainsString("require_once __DIR__.'/bootstrap.php';", $contents, 'servarr.php should not require a separate bootstrap helper');
    }
}
