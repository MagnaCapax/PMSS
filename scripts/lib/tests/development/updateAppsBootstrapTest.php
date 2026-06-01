<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateAppsBootstrapTest extends TestCase
{
    public function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-update-app-bootstrap', 0700);
    }

    public function testServarrInstallerWarnsForEveryAppWhenRuntimeHelperMissing(): void
    {
        $installerFile = 'servarr.php';
        $sourceDir = dirname(__DIR__, 2).'/update/apps';
        $sandboxDir = $this->tempDir.'/apps-'.basename($installerFile, '.php');
        $this->assertTrue(@mkdir($sandboxDir, 0700, true), 'Unable to create sandbox app dir');

        foreach ([$installerFile, 'bootstrap.php', 'arr.php'] as $file) {
            $sourcePath = $sourceDir.'/'.$file;
            if (is_file($sourcePath)) {
                $this->assertTrue(@copy($sourcePath, $sandboxDir.'/'.$file), 'Unable to copy '.$file.' into sandbox');
            }
        }

        $output = $this->pmssRunInlinePhp('include '.var_export($sandboxDir.'/'.$installerFile, true).';', [], '2>&1');
        $this->assertTrue(is_string($output), 'Unable to execute sandboxed installer');

        foreach (['Lidarr', 'Prowlarr', 'Radarr', 'Readarr', 'Sonarr'] as $label) {
            $this->assertStringContainsString($label.' updater: missing runtime helper', $output);
        }
        $this->pmssAssertStringNotContainsString('Fatal error', $output, 'Servarr bootstrap should soft-return when runtime is missing');
    }

    public function testUpdateStep2SkipsHelperModulesInAppLoader(): void
    {
        $venvCase = [
            'required' => ["require_once __DIR__.'/pythonVenv.php';"],
            'forbidden' => ['packageState.php', 'packages/helpers.php'],
        ];
        $this->pmssAssertRepoFileContractCases([
            'scripts/util/update-step2.php' => [
                'required' => ["'arr.php'", "'pythonVenv.php'", "'remoteBinary.php'", "'servarr.php'"],
                'forbidden' => ["'packages.php'"],
            ],
            'scripts/lib/update/apps/python.php' => $venvCase,
            'scripts/lib/update/apps/pyload.php' => $venvCase,
            'scripts/lib/update/apps/arr.php' => [
                'required' => ["dirname(__DIR__, 2).'/runtime.php'", '%s updater: missing runtime helper'],
                'forbidden' => ["require_once __DIR__.'/bootstrap.php';"],
            ],
            'scripts/lib/update/apps/servarr.php' => [
                'required' => ["require_once __DIR__.'/arr.php';", 'pmssArrUpdateSupportedApps();'],
                'forbidden' => ["dirname(__DIR__).'/runtime.php'", 'missing runtime helper', "require_once __DIR__.'/bootstrap.php';"],
            ],
        ]);
    }
}
