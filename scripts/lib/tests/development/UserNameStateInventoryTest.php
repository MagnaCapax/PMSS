<?php
/** Verify shared name-state registration, guarded retirement and reuse reporting with private fixtures. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/nameState.php';

class UserNameStateInventoryTest extends TestCase
{
    public function testInventoryRejectsUnsafeNames(): void
    {
        foreach (array('', '../fixtur01', 'fixtur01/child', "fixtur01\0", '-fixtur01') as $name) {
            try {
                \pmssUserNameStateInventory($name);
                $this->fail('Unsafe inventory name accepted');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('Refusing unsafe username state inventory', $error->getMessage());
            }
        }
    }

    public function testResidueIncludesLinksAndDirectoriesWithoutReadingThem(): void
    {
        $root = $this->pmssMakeTempDir('pmss-name-state-');
        $roots = array_fill_keys(array('iops', 'usage', 'cache', 'cron', 'cron_legacy', 'screen', 'linger'), $root);
        $this->assertSame(array(), \pmssUserNameStateResidue('fixtur01', $roots));
        mkdir($root.'/iopsLimits');
        symlink($root.'/missing', $root.'/iopsLimits/fixtur01');
        mkdir($root.'/S-fixtur01');
        $residue = \pmssUserNameStateResidue('fixtur01', $roots);
        $this->assertSame(array('iops', 'screen'), array_column($residue, 'group'));
        $this->assertSame(array('remove', 'remove'), array_column($residue, 'action'));
        // Exact names only: a neighboring account must not inherit these entries.
        $this->assertSame(array(), \pmssUserNameStateResidue('fixtur02', $roots));
    }

    public function testOneAddedBucketIsReportedOnReuseAndRetiredAtTermination(): void
    {
        $root = $this->pmssMakeTempDir('pmss-name-bucket-');
        mkdir($root.'/fixtureBucket');
        file_put_contents($root.'/fixtureBucket/fixtur01', 'old state');
        file_put_contents($root.'/fixtureBucket/fixtur02', 'neighbor');
        $roots = array_fill_keys(array('iops', 'usage', 'cache', 'cron', 'cron_legacy', 'screen', 'linger'), $root);
        $script = 'namespace NameStateFixture; require_once '.var_export($this->pmssRepoPath('scripts/lib/user/terminationCleanup.php'), true).';';
        $script .= '$GLOBALS["roots"] = '.var_export($roots, true).';';
        // Change inventory data alone: neither lifecycle consumer knows this bucket's name.
        $source = $this->pmssReadRepoFile('scripts/lib/user/nameState.php');
        $source = str_replace("'iopsLimits/'.\$username,", "'fixtureBucket/'.\$username, 'iopsLimits/'.\$username,", $source);
        $source = str_replace('$roots += array(', '$roots += $GLOBALS["roots"]; $roots += array(', $source);
        $source = str_replace('__DIR__', var_export($this->pmssRepoPath('scripts/lib/user'), true), $source);
        $script .= 'eval('.var_export('namespace NameStateFixture;'.substr($source, 5), true).');';
        $script .= <<<'PHP'
class users {}
function pmssUserAccountLookup($name) { return $GLOBALS['live'] ? ['uid' => 1234] : null; }
function pmssPasswdEntryLookup($name) { return null; }
function pmssUserCronSpoolRemoveOrphan($path, $lookup) { return ['status' => 'absent']; }
function pmssUserLifecycleContextLogStatusMessage($a, $p, $u, $s, $m, $x = []) {}
function logProvisionMessage($message) { $GLOBALS['messages'][] = $message; }
function pmssAddUserFatalExit($status, $message, $reason) { throw new \RuntimeException($reason); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/termination/filesystem.php', 'NameStateFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/termination/completion.php', 'NameStateFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/add/preflight.php', 'NameStateFixture');
        $script .= '$root = '.var_export($root, true).';';
        $script .= <<<'PHP'
$out = []; $live = false; $messages = [];
try { pmssAddUserEnsurePreflightState(new users(), ['name' => 'fixtur01'], $root.'/absent'); }
catch (\RuntimeException $error) { $out[] = [$error->getMessage(), $messages]; }
$live = true;
$out[] = pmssTerminateUserClearIopsBudgetState('fixtur01', false, $root);
$out[] = file_exists($root.'/fixtureBucket/fixtur01');
$live = false;
$out[] = pmssTerminateUserClearIopsBudgetState('fixtur01', true, $root);
$out[] = file_exists($root.'/fixtureBucket/fixtur01');
$out[] = pmssTerminateUserClearIopsBudgetState('fixtur01', false, $root);
$out[] = file_exists($root.'/fixtureBucket/fixtur01');
$messages = [];
pmssAddUserEnsurePreflightState(new users(), ['name' => 'fixtur01'], $root.'/absent');
$out[] = $messages;
$out[] = file_get_contents($root.'/fixtureBucket/fixtur02');
echo json_encode($out);
PHP;
        $this->assertSame(array(
            array('username_state_residue', array('Username state requires remove before reuse: '.$root.'/fixtureBucket/fixtur01')),
            false, true, true, true, true, false, array(), 'neighbor',
        ), $this->pmssRunInlinePhpJson($script));
    }

    public function testBothEntryPointsConsumeTheInventory(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/addUser.php', 'pmssAddUserEnsurePreflightState(');
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/add/preflight.php', 'pmssUserNameStateResidue(');
        $this->pmssAssertRepoFileContainsString('scripts/terminateUser.php', 'pmssTerminateUserClearIopsBudgetState(');
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/termination/completion.php', "pmssUserNameStatePaths(\$username, 'iops'");
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/termination/commands.php', "pmssUserNameStatePaths(\$username, 'screen'");
        $this->assertSame('archive', \pmssUserNameStateInventory('fixtur01')['usage']['action']);
    }
}
