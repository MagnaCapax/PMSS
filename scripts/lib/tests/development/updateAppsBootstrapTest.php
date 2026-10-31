<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateAppsBootstrapTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-update-app-bootstrap', 0700]; }

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
        // Keep the isolated installer tree complete while deliberately omitting runtime.php.
        $this->pmssEnsureDir($sandboxDir.'/arr');
        foreach (glob($sourceDir.'/arr/*.php') ?: [] as $module) {
            $this->assertTrue(@copy($module, $sandboxDir.'/arr/'.basename($module)), 'Unable to copy ARR helper');
        }
        $helperPath = $sourceDir.'/../rootOwnedTree.php';
        $this->assertTrue(@copy($helperPath, $this->tempDir.'/rootOwnedTree.php'), 'Unable to copy shared tree helper');

        $output = $this->pmssRunInlinePhp('include '.var_export($sandboxDir.'/'.$installerFile, true).';', [], '2>&1');
        $this->assertTrue(is_string($output), 'Unable to execute sandboxed installer');

        $this->assertStringContainsAndOmitsStrings([
            'Lidarr updater: missing runtime helper', 'Prowlarr updater: missing runtime helper', 'Radarr updater: missing runtime helper',
            'Readarr updater: missing runtime helper', 'Sonarr updater: missing runtime helper',
        ], ['Fatal error' => 'Servarr bootstrap should soft-return when runtime is missing'], $output);
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
