<?php
/** Verify panel facades against mixed loader versions and incomplete local deliveries. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class PanelFacadeLoaderCompatibilityTest extends TestCase
{
    private const GROUPS = [
        'webCgroupMemoryStatus' => ['webCgroupMemorySource', 'webCgroupMemoryPolicy', 'webCgroupMemoryRead', 'welcomeMemory'],
        'storageHealthNotice' => ['storageHealthRaidDisplay', 'storageHealthRaid', 'storageHealthPressure', 'storageHealthDisplay'],
        'statsHelpers' => ['statsState', 'statsStatus', 'statsTraffic', 'statsResourceModel', 'statsResourceRender', 'statsCharts'],
    ];
    private const APIS = ['webCgroupMemoryStatus' => 'pmssWelcomeMemoryStateBuild',
        'storageHealthNotice' => 'pmssStorageHealthNoticeHtmlRead', 'statsHelpers' => 'pmssStatsResourceSnapshotBuild'];

    /** Reproduce the pre-batch-loader Files part without depending on repository history at test time. */
    private function fixture(string $facade, bool $legacy = true): string
    {
        $www = $this->pmssMakeTempDir('pmss-panel-loader-').'/www';
        foreach (array_merge(['scriptsInc', 'scriptsIncFormat', 'scriptsIncFrontend', 'scriptsIncCgroup', $facade], self::GROUPS[$facade]) as $name) {
            $this->pmssWriteFile($www.'/'.$name.'.php', $this->pmssReadRepoFile('etc/skel/www/'.$name.'.php'));
        }
        $files = $this->pmssReadRepoFile('etc/skel/www/scriptsIncFiles.php');
        if ($legacy) {
            $start = strpos($files, "if (!function_exists('pmssWelcomeRequireLocalHelpers'))");
            $this->assertTrue($start !== false);
            $files = substr($files, 0, $start).<<<'PHP'
if (!function_exists('pmssWelcomeRequireLocalHelper')) {
 function pmssWelcomeRequireLocalHelper($file) {
  if (!is_string($file) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\.php\z/', $file) || basename($file) !== $file) return false;
  $path = __DIR__.'/'.$file;
  if (is_file($path) && !is_link($path) && is_readable($path)) { require_once $path; return true; }
  return false;
 }
}
PHP;
        }
        $this->pmssWriteFile($www.'/scriptsIncFiles.php', $files);
        return $www.'/'.$facade.'.php';
    }

    /** Observe definitions and include order without invoking any host collectors. */
    private function probe(string $path, string $facade): array
    {
        return $this->pmssRunInlinePhpRequireJson($path,
            '$modules = '.var_export(self::GROUPS[$facade], true).';'
            .'echo json_encode([array_values(array_intersect(array_map("basename", get_included_files()),'
            .'array_map(function($name) { return $name.".php"; }, $modules))),'
            .'function_exists('.var_export(self::APIS[$facade], true).'), function_exists("pmssWelcomeRequireLocalHelpers")]);',
            [], '2>&1');
    }

    public function testLegacyLoaderLoadsCompleteGroupsInOrder(): void
    {
        foreach (self::GROUPS as $facade => $modules) {
            $this->assertSame([array_map(static function ($name) { return $name.'.php'; }, $modules), true, false],
                $this->probe($this->fixture($facade), $facade), $facade);
        }
    }

    public function testCurrentBatchLoaderKeepsCompleteGroupsInOrder(): void
    {
        foreach (self::GROUPS as $facade => $modules) {
            $this->assertSame([array_map(static function ($name) { return $name.'.php'; }, $modules), true, true],
                $this->probe($this->fixture($facade, false), $facade), $facade);
        }
    }

    public function testMissingLinkedOrDirectorySiblingLoadsNoneOfTheGroup(): void
    {
        foreach ([true, false] as $legacy) {
            foreach (self::GROUPS as $facade => $modules) {
                foreach ($modules as $module) {
                    foreach (['missing', 'linked', 'directory'] as $kind) {
                        $path = $this->fixture($facade, $legacy);
                        $sibling = dirname($path).'/'.$module.'.php';
                        $this->assertTrue(unlink($sibling));
                        if ($kind === 'linked') $this->pmssCreateSymlinkOrSkip($this->pmssRepoPath('etc/skel/www/'.$module.'.php'), $sibling);
                        if ($kind === 'directory') $this->assertTrue(mkdir($sibling));
                        $this->assertSame([[], false, !$legacy], $this->probe($path, $facade), $facade.': '.$module);
                    }
                }
            }
        }
    }

    public function testAbsentLoaderApisKeepTheUnavailableFallback(): void
    {
        foreach (self::GROUPS as $facade => $modules) {
            $path = $this->fixture($facade);
            $this->pmssWriteFile(dirname($path).'/scriptsInc.php', '<?php');
            $this->assertSame([[], false, false], $this->probe($path, $facade), $facade);
        }
    }

    public function testBatchLoaderRefusalDoesNotTryTheLegacyPath(): void
    {
        foreach (self::GROUPS as $facade => $modules) {
            $path = $this->fixture($facade);
            $this->pmssWriteFile(dirname($path).'/scriptsInc.php', '<?php '
                .'function pmssWelcomeRequireLocalHelpers(array $files) { return false; } '
                .'function pmssWelcomeRequireLocalHelper($file) { throw new RuntimeException("Unexpected fallback"); }');
            $this->assertSame([[], false, true], $this->probe($path, $facade), $facade);
        }
    }

    public function testLegacyLoaderRestoresSavedMemoryCardFigure(): void
    {
        $path = $this->fixture('webCgroupMemoryStatus');
        $www = dirname($path);
        $this->pmssWriteFile(dirname($www).'/.resourceData', serialize(['memory' => [
            'current' => 134217728, 'anon' => 100663296, 'file' => 33554432,
        ]]));
        $this->pmssWriteFile(dirname($www).'/.config/pmss-user.json', '{"ramMiB":512}');
        // Saved counters are the subject; prevent the live pressure reader from inspecting the test host.
        $memory = $this->pmssReadRepoFile('etc/skel/www/welcomeMemory.php');
        $this->pmssWriteFile($www.'/welcomeMemory.php', str_replace('pmssWebCgroupMemoryStatusRead()', 'array("available" => false)', $memory));
        $welcome = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $start = strpos($welcome, 'function pmssWelcomeTrafficUpgradeUrlBuild');
        $this->assertTrue($start !== false);
        $render = $this->pmssWriteFile($www.'/welcomeRender.php', "<?php\n".preg_replace('/\?>\s*$/', '', substr($welcome, $start)));
        $html = $this->pmssRunInlinePhpRequire($path,
            'require '.var_export($render, true).'; chdir('.var_export($www, true).');'
            .'echo pmssWelcomeHomeMemoryMetricHtmlBuild(pmssWelcomeMemoryStateBuild());', [], '2>&1');
        $this->assertStringContainsString('96 MiB', $html);
        $this->assertStringContainsString('of 512 MiB', $html);
        $this->pmssAssertStringNotContainsString('unavailable', $html);
        $this->pmssAssertStringNotContainsString('Warning', $html);
        $this->pmssAssertStringNotContainsString('Fatal', $html);
    }
}
