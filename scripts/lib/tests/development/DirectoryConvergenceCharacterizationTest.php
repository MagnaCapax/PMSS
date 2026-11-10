<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/directories.php';

/** Lock directories.php, directoryManaged.php and directoryAccount.php without host mutations. */
class DirectoryConvergenceCharacterizationTest extends TestCase
{
    /** Export the actual implementation beside memory-only filesystem and account probes. */
    private function runFixture(array $options): array
    {
        $script = <<<'PHP'
namespace DirectoryConvergenceFixture;
function function_exists($name) { return strpos($name, 'posix_') === 0 || \function_exists($name); }
function posix_geteuid() { return $GLOBALS['options']['root'] ? 0 : 99; }
function posix_getpwnam($name) { $GLOBALS['events'][] = ['user', $name]; return ['uid' => $GLOBALS['options']['wantedUid'] ?? 0, 'gid' => 41]; }
function posix_getgrnam($name) { $GLOBALS['events'][] = ['group', $name]; return ['gid' => 44]; }
function posix_getpwuid($uid) { $GLOBALS['events'][] = ['account', $uid]; return ['name' => 'fixtur01']; }
function is_link($path) { return $path === ($GLOBALS['options']['link'] ?? null); }
function is_dir($path) { return $path === '/fixture' || $path === '/fixture/leaf'; }
function file_exists($path) { return is_dir($path); }
function lstat($path) {
    $GLOBALS['events'][] = ['stat', $path];
    return ['uid' => $path === '/fixture' ? ($GLOBALS['options']['ancestorUid'] ?? 0) : 0, 'mode' => 0040755];
}
function fileperms($path) { $GLOBALS['events'][] = ['mode', $path]; return $GLOBALS['options']['mode']; }
function fileowner($path) { $GLOBALS['events'][] = ['uid', $path]; return $GLOBALS['options']['uid']; }
function filegroup($path) { $GLOBALS['events'][] = ['gid', $path]; return $GLOBALS['options']['gid']; }
function clearstatcache($all, $path) { $GLOBALS['events'][] = ['clear', $path]; }
function chmod($path, $mode) { $GLOBALS['events'][] = ['chmod', $path, $mode]; return $GLOBALS['options']['chmod']; }
function chown($path, $uid) { $GLOBALS['events'][] = ['chown', $path, $uid]; return $GLOBALS['options']['chown']; }
function chgrp($path, $gid) {
    $GLOBALS['events'][] = ['chgrp', $path, $gid];
    if (!empty($GLOBALS['options']['throwGroup'])) throw $GLOBALS['failure'];
    return $GLOBALS['options']['chgrp'];
}
function mkdir($path, $mode) { throw new \RuntimeException('unexpected creation'); }
function pmssAccountDirectoryEnsure(...$arguments) {
    $GLOBALS['events'][] = ['delegate', array_slice($arguments, 0, 5)];
    return false;
}
PHP;
        // The same public-API trace can characterize the unsplit implementation on the testing VM.
        foreach (['pmssEnsureDir', 'pmssDirectoryManagedEnsure'] as $function) {
            if (function_exists($function)) $script .= \pmssTestingFunctionExport($function, 'DirectoryConvergenceFixture');
        }
        $script .= <<<'PHP'
$GLOBALS['options'] = json_decode(getenv('PMSS_TEST_DIRECTORY_OPTIONS'), true);
$GLOBALS['events'] = [];
$GLOBALS['failure'] = $GLOBALS['options']['throwGroup'] === 'error' ? new \Error('group fault') : new \RuntimeException('group fault');
$caught = null;
$result = null;
try {
    $result = pmssEnsureDir($GLOBALS['options']['path'] ?? '/fixture/leaf', 0755, 'fixtur01', 'fixtur02',
        static function ($message) { $GLOBALS['events'][] = ['log', $message]; }, 0751);
} catch (\Throwable $failure) { $caught = $failure; }
echo json_encode([$result, $GLOBALS['events'], $caught !== null && $caught === $GLOBALS['failure']]);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_DIRECTORY_OPTIONS' => json_encode($options + [
            'root' => true, 'mode' => 0700, 'uid' => 77, 'gid' => 88,
            'chmod' => true, 'chown' => true, 'chgrp' => true, 'throwGroup' => '',
        ])]);
    }

    public function testMetadataReadsAndRepairsKeepOrderAndOneOwnershipNote(): void
    {
        foreach ([false, true] as $chmod) {
            foreach ([false, true] as $chown) {
                foreach ([false, true] as $chgrp) {
                    $events = [['user', 'fixtur01'], ['group', 'fixtur02'], ['stat', '/fixture/leaf'],
                        ['stat', '/fixture'], ['stat', '/fixture'], ['stat', '/fixture/leaf'],
                        ['mode', '/fixture/leaf'], ['chmod', '/fixture/leaf', 0755]];
                    if ($chmod) array_push($events, ['clear', '/fixture/leaf'], ['log', '[WARN] Updated directory mode: /fixture/leaf']);
                    array_push($events, ['uid', '/fixture/leaf'], ['gid', '/fixture/leaf'],
                        ['chown', '/fixture/leaf', 0], ['chgrp', '/fixture/leaf', 44]);
                    if ($chown || $chgrp) $events[] = ['log', '[WARN] Updated directory ownership: /fixture/leaf'];
                    $this->assertSame([true, $events, false], $this->runFixture(compact('chmod', 'chown', 'chgrp')));
                }
            }
        }
    }

    public function testUnavailableAndConvergedMetadataRemainSilent(): void
    {
        foreach ([[false, false, false], [0755, 0, 44]] as [$mode, $uid, $gid]) {
            $this->assertSame([true, [['user', 'fixtur01'], ['group', 'fixtur02'],
                ['stat', '/fixture/leaf'], ['stat', '/fixture'], ['stat', '/fixture'],
                ['stat', '/fixture/leaf'], ['mode', '/fixture/leaf'], ['uid', '/fixture/leaf'], ['gid', '/fixture/leaf']], false],
                $this->runFixture(compact('mode', 'uid', 'gid')));
        }
    }

    public function testNonRootNeverAttemptsOwnershipChanges(): void
    {
        $this->assertSame([true, [['user', 'fixtur01'], ['group', 'fixtur02'], ['stat', '/fixture'],
            ['stat', '/fixture/leaf'], ['mode', '/fixture/leaf']], false], $this->runFixture(['root' => false, 'mode' => 0755]));
    }

    public function testForeignAncestorAndSymlinkRefusalsKeepTheAuthorityBoundary(): void
    {
        $this->assertSame([false, [['user', 'fixtur01'], ['group', 'fixtur02'],
            ['stat', '/fixture/leaf'], ['stat', '/fixture']], false], $this->runFixture(['ancestorUid' => 99]));
        $this->assertSame([false, [['log', '[WARN] Refusing to ensure symlinked dir: /fixture/leaf']], false],
            $this->runFixture(['link' => '/fixture/leaf']));
    }

    public function testMatchingAccountAncestorDelegatesBeforeManagedTraversal(): void
    {
        $this->assertSame([false, [['user', 'fixtur01'], ['group', 'fixtur02'], ['stat', '/fixture/leaf'],
            ['stat', '/fixture'], ['account', 99],
            ['delegate', ['fixtur01', '/fixture', '/fixture/leaf', 0755, 0751]]], false],
            $this->runFixture(['ancestorUid' => 99, 'wantedUid' => 99]));
    }

    public function testOwnershipThrowablesPropagateAfterThePriorRepairWithoutSuccessLog(): void
    {
        foreach (['exception', 'error'] as $throwGroup) {
            [$result, $events, $sameFailure] = $this->runFixture(['throwGroup' => $throwGroup, 'mode' => 0755]);
            $this->assertSame(null, $result);
            $this->assertTrue($sameFailure);
            $this->assertSame([['uid', '/fixture/leaf'], ['gid', '/fixture/leaf'],
                ['chown', '/fixture/leaf', 0], ['chgrp', '/fixture/leaf', 44]], array_slice($events, -4));
        }
    }
}
