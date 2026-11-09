<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Isolate userConfigPersistence.php publication; no host paths are read or written. */
class UserConfigPersistenceCharacterizationTest extends TestCase
{
    public function testPublicationRetainsPreparationOwnershipModesAndFailureOrder(): void
    {
        $script = <<<'PHP'
namespace UserConfigPersistenceFixture;
function pmssManagedAccountHomePath($path) { return $GLOBALS['home']; }
function pmssRootStageContext($path) { $GLOBALS['calls'][] = ['context', $path]; return $GLOBALS['case'] === 'unprivileged' ? null : ['context']; }
function pmssAccountHomeIdentity($path) { $GLOBALS['calls'][] = ['identity', $path]; return $GLOBALS['case'] === 'identity' ? null : ['/home/fixtur01', 'fixtur01', 7]; }
function posix_geteuid() { return 8; }
function is_dir($path) { return $GLOBALS['case'] === 'existing'; }
function pmssAccountDirectoryEnsure(...$args) { $GLOBALS['calls'][] = ['account-directory', $args]; return $GLOBALS['case'] !== 'account-directory'; }
function pmssDirEnsureExists(...$args) { $GLOBALS['calls'][] = ['directory', $args]; return $GLOBALS['case'] !== 'directory'; }
function pmssJsonEncodePretty($payload) { $GLOBALS['calls'][] = ['encode', $payload]; return $GLOBALS['case'] === 'encode' ? null : '{"quota":5}'; }
function pmssWriteManagedFile(...$args) { $GLOBALS['calls'][] = ['write', $args]; return $GLOBALS['case'] !== 'write'; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/userConfigPersistence.php', 'UserConfigPersistenceFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([false, true] as $home) {
    foreach (['success', 'directory', 'identity', 'unprivileged', 'existing', 'account-directory', 'encode', 'write'] as $case) {
        $GLOBALS['calls'] = [];
        $path = $home ? '/home/fixtur01/.config/pmss-user.json' : '/fixture/config/users/fixtur01.json';
        $group = $home ? 'fixtur01' : 'root';
        $result = pmssUserConfigJsonWrite($path, ['quota' => 5], 0640, 'root', $group);
        $results[] = [$home, $case, $result, $GLOBALS['calls']];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(16, count($results));
        foreach ($results as [$home, $case, $result, $calls]) {
            $dir = $home ? '/home/fixtur01/.config' : '/fixture/config/users';
            $expected = $home ? [['context', $dir.'/.pmss-check'], ['identity', $dir.'/.pmss-check']]
                : [['directory', [$dir, 0750]]];
            $prepared = $home ? !in_array($case, ['identity', 'unprivileged', 'account-directory'], true) : $case !== 'directory';
            if ($home && !in_array($case, ['identity', 'unprivileged', 'existing'], true)) {
                $expected[] = ['account-directory', ['fixtur01', '/home/fixtur01', $dir, 0750, 0755]];
            }
            if ($prepared) {
                $expected[] = ['encode', ['quota' => 5]];
                if ($case !== 'encode') {
                    $expected[] = ['write', [$dir.'/'.($home ? 'pmss-user.json' : 'fixtur01.json'),
                        '{"quota":5}', 'root', $home ? 'fixtur01' : 'root', 0640]];
                }
            }
            $this->assertSame($prepared && !in_array($case, ['encode', 'write'], true), $result, $case);
            $this->assertSame($expected, $calls, ($home ? 'home ' : 'canonical ').$case);
        }
    }
}
