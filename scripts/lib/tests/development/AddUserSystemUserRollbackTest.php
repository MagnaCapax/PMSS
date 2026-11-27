<?php
/** Verify unknown useradd status arms rollback only for newly present state. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class AddUserSystemUserRollbackTest extends TestCase
{
    public function testCreationStatusAndPartialStateKeepFailureAndRollbackBoundaries(): void
    {
        $cases = [];
        foreach ([-1, 0, 9, 12, 1] as $rc) {
            foreach ([false, true] as $account) {
                foreach ([false, true] as $home) {
                    $cases[] = [$rc, $account, $home];
                }
            }
        }

        // Intercept every command and account/home probe; no live account is touched.
        $script = <<<'PHP'
namespace AddUserSystemUserRollbackFixture;
function runProvisionStep($description, $command) {
    $GLOBALS['steps'][] = [$description, $command];
    return $GLOBALS['rc'];
}
function pmssUserAccountLookup($name) {
    $GLOBALS['lookups'][] = $name;
    return $GLOBALS['account'] ? ['uid' => 1234] : null;
}
function is_dir($path) {
    $GLOBALS['homes'][] = $path;
    return $GLOBALS['home'];
}
function function_exists($name) {
    return $name === 'pmssAddUserFailureRollbackMarkSystemUserCreated' || \function_exists($name);
}
function pmssAddUserFatalExit($status, $detail, $code) {
    $GLOBALS['fatal'] = [$status, $detail, $code];
    $GLOBALS['armedAtFatal'] = !empty(pmssAddUserFailureRollbackStateRead()['systemUserCreated']);
    throw new \RuntimeException('failure boundary');
}
function pmssUserConfigNormaliseToggleValue($user, $key) {
    // A successful create reaches policy setup; stop before later provisioning.
    throw new \RuntimeException('success boundary');
}
PHP;
        foreach (['failureRollback.php', 'systemUserCreate.php'] as $module) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/add/'.$module, 'AddUserSystemUserRollbackFixture');
        }
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_USERADD_ROLLBACK'), true) as [$rc, $account, $home]) {
    $GLOBALS['rc'] = $rc;
    $GLOBALS['account'] = $account;
    $GLOBALS['home'] = $home;
    $GLOBALS['steps'] = $GLOBALS['lookups'] = $GLOBALS['homes'] = [];
    $GLOBALS['fatal'] = null;
    $GLOBALS['armedAtFatal'] = false;
    pmssAddUserFailureRollbackStateWrite([]);
    try {
        pmssAddUserSystemUserCreate(['name' => 'fixtur01'], '/home/fixtur01');
        throw new \LogicException('creation did not reach a boundary');
    } catch (\RuntimeException $error) {
        $boundary = $error->getMessage();
    }
    $state = pmssAddUserFailureRollbackStateRead();
    $results[] = [$boundary, $GLOBALS['fatal'], $GLOBALS['armedAtFatal'],
        !empty($state['systemUserCreated']),
        pmssAddUserFailureRollbackShouldRun($state, ['status' => 'FAIL', 'exit_code' => 1]),
        $GLOBALS['steps'], $GLOBALS['lookups'], $GLOBALS['homes']];
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_USERADD_ROLLBACK' => json_encode($cases)]);
        $this->assertSame(count($cases), count($results));
        foreach ($cases as $index => [$rc, $account, $home]) {
            $armed = $rc === 0 || ($rc === -1 && ($account || $home));
            $fatal = $rc === 0 ? null : ['FAIL', 'Create system user failed; aborting provisioning', 'useradd_failed'];
            $this->assertSame([
                $rc === 0 ? 'success boundary' : 'failure boundary', $fatal,
                $rc !== 0 && $armed, $armed, $armed,
                [['Create system user', "useradd --skel /etc/skel -m 'fixtur01'"]],
                $rc === -1 ? ['fixtur01'] : [],
                $rc === -1 && !$account ? ['/home/fixtur01'] : [],
            ], $results[$index], 'case '.$index);
        }
    }
}
