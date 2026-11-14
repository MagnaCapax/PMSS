<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/cron/checkGui.php';
require_once dirname(__DIR__, 2).'/testing/filesystem.php';

/** Lock the filesystem.php facade and checkGui.php sequences without touching account files. */
class UserSkeletonDeliveryCharacterizationTest extends TestCase
{
    public function testFacadeLoadsEveryMaintenanceResponsibility(): void
    {
        foreach ([
            'filesystemPaths.php' => ['pmssUserPathWithinHomeRoot', 'pmssUserDeletePathIfPresent',
                'pmssUserRemoveLegacyOpenClawInstaller', 'pmssUserEnsureWebRootSymlinks'],
            'filesystemPatch.php' => ['pmssUserPatchWritableFile', 'pmssUserPatchWritableStrings'],
            'panelCompatibility.php' => ['pmssUserPanelIndexNeedsFrameDataCompatRefresh', 'pmssUserRefreshPanelIndexForFrameDataCompat'],
            'panelFiles.php' => ['pmssUserPanelHelperFiles'],
        ] as $module => $functions) {
            foreach ($functions as $function) {
                $this->assertSame($this->pmssRepoPath('scripts/lib/update/users/'.$module),
                    (new \ReflectionFunction($function))->getFileName());
            }
        }
    }

    public function testUpdaterPreservesCompleteManifestAndMaintenanceOrder(): void
    {
        $result = $this->trace('pmssUserApplySkeletonFiles', 'pmssUserApplySkeletonFiles(["user" => "fixtur01", "home" => "/fixture/home/fixtur01"]);');
        // Digest captured from the original 143 literal entries, each followed by LF.
        $this->assertSame(143, count($result['files']));
        $this->assertSame('d34a225e5161e8a5f630f970d7410ab1158095dad34a400ee036757583f0d437',
            hash('sha256', implode("\n", $result['files'])."\n"));
        $this->assertSame(['traffic', 'panel', 'links', 'migration', 'cleanup', 'delete', 'delete', 'delete',
            'delete', 'patch', 'glob', 'counts', 'log'], array_column($result['events'], 0));
        $this->assertSame(['/fixture/home/fixtur01/www/filemanager.php', 6], array_slice($result['events'][9], 1));
        $this->assertSame(['log', '[user:fixtur01] Root-stage skeleton delivery summary: 2 delivered, 1 failed'], end($result['events']));
    }

    public function testWatchdogPreservesAllTargetSourceAndLabelArguments(): void
    {
        $result = $this->trace('pmssCheckGuiMain', '$GLOBALS["rc"] = pmssCheckGuiMain([]);');
        $this->assertSame(0, $result['rc']);
        $expected = ['toggleAppCatalog.php', 'index.php', 'scriptsInc.php', 'scriptsIncFormat.php', 'scriptsIncFiles.php',
            'scriptsIncFrontend.php', 'scriptsIncBonus.php', 'scriptsIncCgroup.php', 'scriptsIncBackup.php',
            'scriptsIncRestoreTar.php', 'scriptsIncRestore.php', 'scriptsIncAppProcess.php', 'scriptsIncAppsHtml.php',
            'scriptsIncAppsStatus.php', 'scriptsIncActionJs.php'];
        $this->assertSame($expected, array_column($result['files'], 3));
        foreach ($result['files'] as $index => $args) {
            $this->assertSame(['/fixture/home/fixtur01/www/'.$expected[$index], '/etc/skel/www/'.$expected[$index],
                'fixtur01', $expected[$index], '/fixture/home/fixtur01'], $args);
        }
        $this->assertSame(['reconcile', 'directory', 'directory'], array_column($result['events'], 0));
    }

    public function testStringPatchesRetainShieldingOrderingAndNoOpWrites(): void
    {
        $sentinel = '__PMSS_PATCHED_'.sha1('patched legacy').'__';
        foreach ([
            ['legacy', [['legacy', 'patched legacy']], 'patched legacy'],
            ['patched legacy legacy', [['legacy', 'patched legacy']], 'patched legacy patched legacy'],
            [$sentinel.' legacy patched legacy', [['legacy', 'patched legacy']], $sentinel.' patched legacy patched legacy'],
            ['abab', [['ab', 'bab']], 'abab'],
            ['legacy', [['legacy', 'middle'], ['middle', 'final']], 'final'],
            ['patched', [['legacy', 'patched']], 'patched'],
            ['', [['legacy', 'patched']], ''],
            ['legacy', [['', 'patched']], 'legacy'],
            ['legacy', [['legacy', '']], ''],
            ['legacy', [], 'legacy'],
        ] as [$content, $pairs, $expected]) {
            $replacements = array_map(static function ($pair): array { return ['legacy' => $pair[0], 'patched' => $pair[1]]; }, $pairs);
            $script = '$GLOBALS["content"] = '.var_export($content, true).';'
                .'pmssUserPatchWritableStrings("/fixture/home/fixtur01/file", '.var_export($replacements, true).');';
            $result = $this->trace('pmssUserPatchWritableStrings', $script);
            $this->pmssAssertArraySubsetSame(['content' => $expected, 'events' => $expected !== $content ? [['write']] : []], $result);
        }
    }

    /** Export the real functions; every effect below records arguments in memory. */
    private function trace(string $entry, string $call): array
    {
        $script = <<<'PHP'
namespace UserSkeletonDeliveryFixture;
$GLOBALS['files'] = $GLOBALS['events'] = [];
$GLOBALS['content'] = null;
$GLOBALS['rc'] = null;
class Logger { function __construct($path) {} function msg($message) {} }
function pmssUserContextIdentity($ctx) { return [$ctx['user'], $ctx['home']]; }
function pmssTrafficLimitHomeArtifactReconcile($user, $root, $value, $log) { $GLOBALS['events'][] = ['traffic']; }
function pmssUserRefreshPanelIndexForFrameDataCompat($ctx) { $GLOBALS['events'][] = ['panel']; }
function pmssUserEnsureWebRootSymlinks($ctx) { $GLOBALS['events'][] = ['links']; }
function pmssUserMigrateWebRootState($ctx) { $GLOBALS['events'][] = ['migration']; }
function updateUserFile($file, $user) { $GLOBALS['files'][] = $file; }
function pmssUserRemoveLegacyOpenClawInstaller($user, $home) { $GLOBALS['events'][] = ['cleanup']; }
function pmssUserDeletePathIfPresent($path) { $GLOBALS['events'][] = ['delete', $path]; }
function pmssUserPatchWritableStrings($path, $patches) { $GLOBALS['events'][] = ['patch', $path, count($patches)]; }
function pmssSkeletonBase() { return '/fixture/skel'; }
function glob($pattern) { $GLOBALS['events'][] = ['glob', $pattern]; return []; }
function pmssUpdateUserRootStageCounts($user) { $GLOBALS['events'][] = ['counts']; return [2, 1]; }
function logMessage($message) { $GLOBALS['events'][] = ['log', $message]; }
function pmssResolvePathFromEnv($key, $default) { return '/fixture/home'; }
function pmssManagedHomeUsersList() { return ['fixtur01']; }
function pmssCheckGuiManagedUserNameNormalize($raw) { return $raw; }
function file_exists($path) { return false; }
function is_dir($path) { return true; }
function is_link($path) { return false; }
function pmssCheckGuiWebRootReconcileIfSentinelMissing($user, $home, $log) { $GLOBALS['events'][] = ['reconcile']; }
function pmssCheckGuiEnsureUserDirectory($path, $user, $label, $log, $home) { $GLOBALS['events'][] = ['directory', $label]; return true; }
function pmssCheckGuiRestoreUserFile($target, $source, $user, $label, $log, $home) { $GLOBALS['files'][] = [$target, $source, $user, $label, $home]; }
function pmssUserPathWithinHomeRoot($path) { return true; }
function pmssReadRegularFileContents($path) { return $GLOBALS['content']; }
function pmssReplaceUserFilePreservingMetadata($path, $content) { $GLOBALS['content'] = $content; $GLOBALS['events'][] = ['write']; }
PHP;
        // The patch scenario needs the implementation in place of its orchestration stub.
        if ($entry === 'pmssUserPatchWritableStrings') {
            $script = str_replace("function pmssUserPatchWritableStrings(\$path, \$patches) { \$GLOBALS['events'][] = ['patch', \$path, count(\$patches)]; }", '', $script);
            $script .= \pmssTestingFunctionExport('pmssUserPatchWritableFile', 'UserSkeletonDeliveryFixture');
        }
        $script .= \pmssTestingFunctionExport('pmssUserPanelHelperFiles', 'UserSkeletonDeliveryFixture');
        $script .= \pmssTestingFunctionExport($entry, 'UserSkeletonDeliveryFixture');
        return $this->pmssRunInlinePhpJson($script.$call.'echo json_encode(array_intersect_key($GLOBALS, array_flip(["files", "events", "content", "rc"])));');
    }
}
