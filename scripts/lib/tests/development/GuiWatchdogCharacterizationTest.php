<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/cron/checkGui.php';
require_once dirname(__DIR__, 2).'/testing/filesystem.php';

/** Lock checkGui.php, gui/watchdogPaths.php and gui/watchdogRepair.php without account I/O. */
class GuiWatchdogCharacterizationTest extends TestCase
{
    public function testFacadeRetainsEveryPublicHelper(): void
    {
        foreach ([
            'watchdogPaths.php' => ['pmssCheckGuiManagedUserNameNormalize', 'pmssCheckGuiUserPathIsSafe',
                'pmssCheckGuiApplyOwnership', 'pmssCheckGuiWebRootSentinelsHealthy', 'pmssCheckGuiWebRootReconcileIfSentinelMissing'],
            'watchdogRepair.php' => ['pmssCheckGuiEnsureUserDirectory', 'pmssCheckGuiRestoreUserFile', 'pmssCheckGuiRestoreUserIndex'],
        ] as $module => $functions) {
            foreach ($functions as $function) {
                $this->assertSame($this->pmssRepoPath('scripts/lib/gui/'.$module),
                    (new \ReflectionFunction($function))->getFileName());
            }
        }
    }

    public function testOwnershipRefusalsRetainProbeOrderAndMessageBytes(): void
    {
        $prefix = ['clearstatcache', 'is_link'];
        $regular = array_merge($prefix, ['is_file', 'function_exists', 'posix_getpwnam', 'lstat']);
        foreach ([
            [['link' => true], false, $prefix, 'refusing to apply ownership to symlinked'],
            [['file' => false, 'directory' => false], false, array_merge($prefix, ['is_file', 'is_dir']), 'unable to apply ownership to'],
            [['file' => false], true, array_merge($prefix, ['is_file', 'is_dir', 'function_exists', 'posix_getpwnam', 'lstat']), null],
            [['account' => false], false, $regular, 'unable to apply ownership to'],
            [['stat' => false], false, $regular, 'unable to apply ownership to'],
            [['stat' => ['uid' => 702]], false, $regular, 'unable to apply ownership to'],
            [['posix' => false], false, array_merge($prefix, ['is_file', 'function_exists', 'lstat']), 'unable to apply ownership to'],
            [[], true, $regular, null],
        ] as [$case, $ok, $events, $reason]) {
            $script = <<<'PHP'
namespace GuiOwnershipFixture;
function probe($name, $value) { $GLOBALS['events'][] = $name; return $value; }
function clearstatcache($real, $path) { probe('clearstatcache', null); }
function is_link($path) { return probe('is_link', $GLOBALS['case']['link'] ?? false); }
function is_file($path) { return probe('is_file', $GLOBALS['case']['file'] ?? true); }
function is_dir($path) { return probe('is_dir', $GLOBALS['case']['directory'] ?? true); }
function function_exists($name) { return probe('function_exists', $GLOBALS['case']['posix'] ?? true); }
function posix_getpwnam($user) { return probe('posix_getpwnam', $GLOBALS['case']['account'] ?? ['uid' => 701]); }
function lstat($path) { return probe('lstat', $GLOBALS['case']['stat'] ?? ['uid' => 701]); }
PHP;
            $script .= \pmssTestingFunctionExport('pmssCheckGuiApplyOwnership', 'GuiOwnershipFixture');
            $script .= '$GLOBALS["case"] = '.var_export($case, true).'; $GLOBALS["events"] = $messages = [];'
                .'$ok = pmssCheckGuiApplyOwnership("/fixture/target", "fixtur01", function ($message) use (&$messages) { $messages[] = $message; });'
                .'echo json_encode([$ok, $GLOBALS["events"], $messages]);';
            $this->assertSame([$ok, $events, $reason === null ? [] : ['Skipping fixtur01: '.$reason.' /fixture/target']],
                $this->pmssRunInlinePhpJson($script));
        }
    }

    public function testQueueRetainsSkipsRepairOrderAndIgnoredFailures(): void
    {
        foreach ([
            [[], ['reconcile', 'www', 'data', 'toggleAppCatalog.php', 'index.php', 'scriptsInc.php', 'fixtureHelper.php'], []],
            [['invalid' => true], [], ['Skipping invalid managed-user entry while checking GUI files']],
            [['suspended' => true], [], []],
            [['missing' => true], [], ['Skipping fixtur01: home directory missing or unsafe at /fixture/home/fixtur01']],
            [['link' => true], [], ['Skipping fixtur01: home directory missing or unsafe at /fixture/home/fixtur01']],
            [['www' => false], ['reconcile', 'www'], []],
            [['data' => false, 'reconcile' => false, 'file' => false],
                ['reconcile', 'www', 'data', 'toggleAppCatalog.php', 'index.php', 'scriptsInc.php', 'fixtureHelper.php'], []],
            [['throw' => true], ['reconcile', 'www', 'data', 'toggleAppCatalog.php'], []],
        ] as [$case, $events, $messages]) {
            $script = <<<'PHP'
namespace GuiQueueFixture;
class Logger { function __construct($file) {} function msg($message) { $GLOBALS['messages'][] = $message; } }
function pmssResolvePathFromEnv($key, $default) { return '/fixture/home///'; }
function pmssManagedHomeUsersList() { return ['fixtur01']; }
function pmssCheckGuiManagedUserNameNormalize($raw) { return empty($GLOBALS['case']['invalid']) ? $raw : null; }
function file_exists($path) { return $GLOBALS['case']['suspended'] ?? false; }
function is_dir($path) { return empty($GLOBALS['case']['missing']); }
function is_link($path) { return $GLOBALS['case']['link'] ?? false; }
function pmssCheckGuiWebRootReconcileIfSentinelMissing($user, $home, $log) {
    $GLOBALS['events'][] = 'reconcile'; return $GLOBALS['case']['reconcile'] ?? true;
}
function pmssCheckGuiEnsureUserDirectory($path, $user, $label, $log, $home) {
    $GLOBALS['events'][] = $label; return $GLOBALS['case'][$label] ?? true;
}
function pmssUserPanelHelperFiles() { return ['fixtureHelper.php']; }
function pmssCheckGuiRestoreUserFile($target, $source, $user, $label, $log, $home) {
    $GLOBALS['events'][] = $label;
    if (!empty($GLOBALS['case']['throw'])) throw new \RuntimeException('fixture failure');
    return $GLOBALS['case']['file'] ?? true;
}
PHP;
            $script .= \pmssTestingFunctionExport('pmssCheckGuiMain', 'GuiQueueFixture');
            $script .= '$GLOBALS["case"] = '.var_export($case, true).'; $GLOBALS["events"] = $GLOBALS["messages"] = [];'
                .'try { $rc = pmssCheckGuiMain([]); } catch (\RuntimeException $e) { $rc = $e->getMessage(); }'
                .'echo json_encode([$rc, $GLOBALS["events"], $GLOBALS["messages"]]);';
            $this->assertSame([empty($case['throw']) ? 0 : 'fixture failure', $events, $messages],
                $this->pmssRunInlinePhpJson($script));
        }
    }

    public function testDirectoryRepairRetainsFinalCheckAndShortCircuits(): void
    {
        foreach ([
            [['safe' => false], false, ['safe'], ['Skipping fixtur01: unsafe www directory target /fixture/home/fixtur01/www']],
            [['exists' => true], true, ['safe', 'is_dir'], []],
            [['conflict' => true], false, ['safe', 'is_dir', 'file_exists'],
                ['Skipping fixtur01: expected www directory but found non-directory at /fixture/home/fixtur01/www']],
            [['create' => false], false, ['safe', 'is_dir', 'file_exists', 'create'],
                ['Restoring www directory for user fixtur01', 'Skipping fixtur01: unable to create www directory at /fixture/home/fixtur01/www']],
            [['owner' => false], false, ['safe', 'is_dir', 'file_exists', 'create', 'owner'], ['Restoring www directory for user fixtur01']],
            [['final' => false], false, ['safe', 'is_dir', 'file_exists', 'create', 'owner', 'is_dir'], ['Restoring www directory for user fixtur01']],
            [[], true, ['safe', 'is_dir', 'file_exists', 'create', 'owner', 'is_dir'], ['Restoring www directory for user fixtur01']],
        ] as [$case, $ok, $events, $messages]) {
            $script = <<<'PHP'
namespace GuiDirectoryFixture;
function probe($name, $value) { $GLOBALS['events'][] = $name; return $value; }
function pmssCheckGuiUserPathIsSafe($path, $home, $directory) { return probe('safe', $GLOBALS['case']['safe'] ?? true); }
function is_dir($path) {
    $key = in_array('create', $GLOBALS['events'], true) ? 'final' : 'exists';
    return probe('is_dir', $GLOBALS['case'][$key] ?? ($key === 'final'));
}
function file_exists($path) { return probe('file_exists', $GLOBALS['case']['conflict'] ?? false); }
function pmssAccountDirectoryEnsure($user, $home, $path, $mode, $parentMode) { return probe('create', $GLOBALS['case']['create'] ?? true); }
function pmssCheckGuiApplyOwnership($path, $user, $log) { return probe('owner', $GLOBALS['case']['owner'] ?? true); }
PHP;
            $script .= \pmssTestingFunctionExport('pmssCheckGuiEnsureUserDirectory', 'GuiDirectoryFixture');
            $script .= '$GLOBALS["case"] = '.var_export($case, true).'; $GLOBALS["events"] = $messages = [];'
                .'$ok = pmssCheckGuiEnsureUserDirectory("/fixture/home/fixtur01/www", "fixtur01", "www",'
                .'function ($message) use (&$messages) { $messages[] = $message; }, "/fixture/home/fixtur01/");'
                .'echo json_encode([$ok, $GLOBALS["events"], $messages]);';
            $this->assertSame([$ok, $events, $messages], $this->pmssRunInlinePhpJson($script));
        }
    }
}
