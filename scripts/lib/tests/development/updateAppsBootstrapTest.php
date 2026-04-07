<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateAppsBootstrapTest extends TestCase
{
    /**
     * @var string
     */
    private $tempDir;

    public function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-update-app-bootstrap', 0700);
    }

    private function appBootstrapOutput(string $installerFile): string
    {
        $sourceDir = dirname(__DIR__, 2).'/update/apps';
        $sandboxDir = $this->tempDir.'/apps';
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

    public function testRadarrWarnsAndReturnsWhenRuntimeHelperMissing(): void
    {
        $output = $this->appBootstrapOutput('radarr.php');

        $this->assertStringContainsString('Radarr updater: missing runtime helper', $output);
        $this->assertTrue(strpos($output, 'Fatal error') === false, 'Radarr bootstrap should soft-return when runtime is missing');
    }

    public function testSonarrWarnsAndReturnsWhenRuntimeHelperMissing(): void
    {
        $output = $this->appBootstrapOutput('sonarr.php');

        $this->assertStringContainsString('Sonarr updater: missing runtime helper', $output);
        $this->assertTrue(strpos($output, 'Fatal error') === false, 'Sonarr bootstrap should soft-return when runtime is missing');
    }

    public function testUpdateStep2SkipsHelperModulesInAppLoader(): void
    {
        $contents = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $this->assertStringContainsString("'arr.php'", $contents);
        $this->assertStringContainsString("'pythonVenv.php'", $contents);
        $this->assertStringContainsString("'remoteBinary.php'", $contents);
    }

    public function testPythonVenvInstallersAvoidPackageQueueHelpers(): void
    {
        foreach (['python.php', 'pyload.php'] as $installer) {
            $contents = $this->pmssReadUpdateAppFile($installer);

            $this->assertStringContainsString("require_once __DIR__.'/pythonVenv.php';", $contents);
            $this->assertTrue(
                strpos($contents, "packages/helpers.php") === false,
                $installer.' should not pull package queue helpers when it only needs the shared venv runtime'
            );
        }
    }

    public function testArrHelperKeepsSharedRuntimeBootstrapPath(): void
    {
        $contents = $this->pmssReadUpdateAppFile('arr.php');

        $this->assertStringContainsString("dirname(__DIR__, 2).'/runtime.php'", $contents);
        $this->assertStringContainsString('%s updater: missing runtime helper', $contents);
        $this->assertTrue(strpos($contents, "require_once __DIR__.'/bootstrap.php';") === false, 'ARR helper should not require a separate bootstrap helper');
    }

    public function testStarrInstallersDelegateRuntimeBootstrapToArrHelper(): void
    {
        foreach (['radarr.php', 'sonarr.php'] as $installer) {
            $contents = $this->pmssReadUpdateAppFile($installer);

            $this->assertStringContainsString("require_once __DIR__.'/arr.php';", $contents);
            $this->assertTrue(
                strpos($contents, "dirname(__DIR__).'/runtime.php'") === false,
                $installer.' should delegate runtime bootstrap to arr.php'
            );
            $this->assertTrue(
                strpos($contents, 'missing runtime helper') === false,
                $installer.' should keep the runtime warning in arr.php'
            );
            $this->assertTrue(
                strpos($contents, "require_once __DIR__.'/bootstrap.php';") === false,
                $installer.' should not require a separate bootstrap helper'
            );
        }
    }
}
