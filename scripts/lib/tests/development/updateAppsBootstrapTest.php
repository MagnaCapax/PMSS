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
            $this->pmssAssertUpdateAppFileContainsAndOmitsStrings($installer, ["require_once __DIR__.'/pythonVenv.php';"], [
                'packageState.php' => $installer.' should not pull package-state helpers when it only needs the shared venv runtime',
                'packages/helpers.php' => $installer.' should not pull package-state helpers when it only needs the shared venv runtime',
            ]);
        }
    }

    public function testArrHelperKeepsSharedRuntimeBootstrapPath(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('arr.php', ["dirname(__DIR__, 2).'/runtime.php'", '%s updater: missing runtime helper'], ["require_once __DIR__.'/bootstrap.php';" => 'ARR helper should not require a separate bootstrap helper']);
    }

    public function testServarrInstallerDelegatesRuntimeBootstrapToArrHelper(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('servarr.php', ["require_once __DIR__.'/arr.php';", 'pmssArrUpdateSupportedApps();'], [
            "dirname(__DIR__).'/runtime.php'" => 'servarr.php should delegate runtime bootstrap to arr.php',
            'missing runtime helper' => 'servarr.php should keep the runtime warning in arr.php',
            "require_once __DIR__.'/bootstrap.php';" => 'servarr.php should not require a separate bootstrap helper',
        ]);
    }
}
