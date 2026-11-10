<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/directories.php';

/** Characterize user/directoryAccount.php through the existing public helper. */
class DirectoryAccountCharacterizationTest extends TestCase
{
    private function runFixture(array $options): array
    {
        $script = <<<'PHP'
namespace DirectoryAccountFixture;
function pmssPathTargetIsSafe(...$arguments) { return true; }
function pmssPathSegmentsAreSafe(...$arguments) { return true; }
function is_dir($path) { return true; }
function is_link($path) { return false; }
function file_exists($path) { return false; }
function clearstatcache(...$arguments) {}
function lstat($path) { return $GLOBALS['options']['existing'] ? ['mode' => 0700] : false; }
function pmssAccountPathRun($user, $home, $paths, $command) {
    $GLOBALS['events'][] = [$user, $home, $paths, $command];
    return $GLOBALS['options']['success'];
}
PHP;
        $script .= \pmssTestingFunctionExport('pmssAccountDirectoryEnsure', 'DirectoryAccountFixture');
        $script .= <<<'PHP'
$GLOBALS['options'] = json_decode(getenv('PMSS_TEST_ACCOUNT_DIRECTORY'), true);
$GLOBALS['events'] = [];
$result = pmssAccountDirectoryEnsure('fixtur01', '/fixture/', $GLOBALS['options']['path'],
    $GLOBALS['options']['mode'], 0751, static function ($message) { $GLOBALS['events'][] = ['log', $message]; });
echo json_encode([$result, $GLOBALS['events']]);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_ACCOUNT_DIRECTORY' => json_encode($options + [
            'existing' => false, 'success' => true, 'path' => '/fixture/one/two', 'mode' => 0755,
        ])]);
    }

    public function testNestedCommandsKeepParentsBeforeLeafAndTheirExactModes(): void
    {
        foreach ([false, true] as $existing) {
            $events = [['fixtur01', '/fixture', ['/fixture/one'],
                "test ! -L '/fixture/one' && { test -d '/fixture/one' || mkdir -m 0751 -- '/fixture/one'; }"]];
            if (!$existing) $events[] = ['log', '[WARN] Created directory: /fixture/one'];
            $events[] = ['fixtur01', '/fixture', ['/fixture/one/two'],
                "test ! -L '/fixture/one/two' && { test -d '/fixture/one/two' || mkdir -m 0755 -- '/fixture/one/two'; } && chmod 0755 -- '/fixture/one/two'"];
            $events[] = ['log', $existing ? '[WARN] Updated directory mode: /fixture/one/two' : '[WARN] Created directory: /fixture/one/two'];
            $this->assertSame([true, $events], $this->runFixture(compact('existing')));
        }
    }

    public function testFailedParentCommandStopsBeforeLeafAndSuccessNotes(): void
    {
        $this->assertSame([false, [['fixtur01', '/fixture', ['/fixture/one'],
            "test ! -L '/fixture/one' && { test -d '/fixture/one' || mkdir -m 0751 -- '/fixture/one'; }"]]],
            $this->runFixture(['success' => false]));
    }

    public function testHomeModeUsesTheHomeSentinelAndNoCreationCommand(): void
    {
        $this->assertSame([true, [['fixtur01', '/fixture', ['/fixture/.pmss-directory-check'],
            "test ! -L '/fixture' && chmod 0755 -- '/fixture'"]]], $this->runFixture(['path' => '/fixture']));
    }

    public function testOutOfHomePathsAndInvalidModesNeverReachAccountExecution(): void
    {
        foreach (['/outside/leaf', '/fixture-other/leaf', 'relative'] as $path) {
            $this->assertSame([false, []], $this->runFixture(compact('path')));
        }
        foreach ([-1, 01000] as $mode) {
            $this->assertSame([false, []], $this->runFixture(compact('mode')));
        }
    }
}
