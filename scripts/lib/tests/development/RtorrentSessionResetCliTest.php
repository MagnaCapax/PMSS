<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise the operator CLI without changing identity, account files or processes. */
final class RtorrentSessionResetCliTest extends TestCase
{
    private function observe(array $case = []): array
    {
        $source = $this->pmssReadRepoFile('scripts/util/rtorrentSessionReset.php');
        $source = substr($source, strpos($source, '<?php') + 5);
        $source = preg_replace('/^\h*require_once [^\n]+;\n/m', '', $source);
        $script = <<<'PHP'
namespace RtorrentSessionResetCliFixture;
function pmssValidateUsername($user) { return \pmssValidateUsername($user); }
function pmssPasswdEntryPositiveUid($account) { return \pmssPasswdEntryPositiveUid($account); }
function pmssRunCliEntrypointWithArgv(...$args) {}
function pmssCliReturnWithStderr($message) { $GLOBALS['messages'][] = $message; return 1; }
function fwrite($stream, $message) { $GLOBALS['messages'][] = $message; }
function function_exists($name) { return $name !== ($GLOBALS['case']['missingFunction'] ?? ''); }
function posix_geteuid() { return $GLOBALS['uid']; }
function posix_getpwnam($user) {
    $GLOBALS['events'][] = 'lookup';
    return array_key_exists('account', $GLOBALS['case']) ? $GLOBALS['case']['account']
        : ['uid' => 1234, 'gid' => 1234, 'dir' => '/home/fixtur01'];
}
function posix_initgroups($user, $gid) { $GLOBALS['events'][] = ['groups', $user, $gid]; return ($GLOBALS['case']['fail'] ?? '') !== 'groups'; }
function posix_setgid($gid) { $GLOBALS['events'][] = ['gid', $gid]; return ($GLOBALS['case']['fail'] ?? '') !== 'gid'; }
function posix_setuid($uid) {
    $GLOBALS['events'][] = ['uid', $uid];
    if (($GLOBALS['case']['fail'] ?? '') === 'uid') return false;
    if (empty($GLOBALS['case']['wrongUid'])) $GLOBALS['uid'] = $uid;
    return true;
}
function rtorrentProcessResetSessionDirectory($home, $user, $log) {
    $GLOBALS['events'][] = ['reset', $home, $user, $GLOBALS['uid']];
    $log('session.broken-fixture', true);
    return $GLOBALS['case']['resetSuccess'] ?? true;
}
PHP;
        $script .= 'require_once '.var_export($this->pmssRepoPath('scripts/lib/user/identity.php'), true).';';
        $script .= 'eval('.var_export('namespace RtorrentSessionResetCliFixture;'.$source, true).');';
        $script .= <<<'PHP'
$GLOBALS['case'] = json_decode(getenv('PMSS_TEST_SESSION_CLI'), true);
$GLOBALS['uid'] = $GLOBALS['case']['uid'] ?? 0;
$GLOBALS['events'] = $GLOBALS['messages'] = [];
$rc = pmssRtorrentSessionResetCli($GLOBALS['case']['argv'] ?? ['script', 'fixtur01']);
echo json_encode([$rc, $GLOBALS['events'], $GLOBALS['messages']]);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_SESSION_CLI' => json_encode($case)]);
    }

    public function testInvalidArgumentsNeverReachAccountLookup(): void
    {
        foreach ([[], ['script'], ['script', '../root'], ['script', 'FIXTUR01'],
            ['script', "fixtur01\n"], ['script', null], ['script', 'fixtur01', 'extra']] as $argv) {
            $result = $this->observe(['argv' => $argv]);
            $this->assertSame(1, $result[0]);
            $this->assertSame([], $result[1]);
        }
    }

    public function testNonRootAndMissingPosixFailBeforeLookup(): void
    {
        foreach ([['uid' => 1234], ['missingFunction' => 'posix_setuid']] as $case) {
            $result = $this->observe($case);
            $this->assertSame(1, $result[0]);
            $this->assertSame([], $result[1]);
        }
    }

    public function testMissingRootOrMismatchedAccountNeverDropsIdentity(): void
    {
        foreach ([false, ['uid' => 0, 'gid' => 0, 'dir' => '/home/fixtur01'],
            ['uid' => 1234, 'dir' => '/home/fixtur01'],
            ['uid' => 1234, 'gid' => 1234, 'dir' => '/elsewhere']] as $account) {
            $result = $this->observe(['account' => $account]);
            $this->assertSame(1, $result[0]);
            $this->assertSame(['lookup'], $result[1]);
        }
    }

    public function testFailedIdentityTransitionsNeverReachRecovery(): void
    {
        foreach ([['fail' => 'groups'], ['fail' => 'gid'], ['fail' => 'uid'], ['wrongUid' => true]] as $case) {
            $result = $this->observe($case);
            $this->assertSame(1, $result[0]);
            $this->assertFalse(strpos(json_encode($result[1]), 'reset') !== false);
        }
    }

    public function testRecoveryRunsAsTheAccountAndPropagatesItsOutcomeAndBackupDiagnostic(): void
    {
        foreach ([true, false] as $success) {
            $result = $this->observe(['resetSuccess' => $success]);
            $this->assertSame($success ? 0 : 1, $result[0]);
            $this->assertSame(['lookup', ['groups', 'fixtur01', 1234], ['gid', 1234], ['uid', 1234],
                ['reset', '/home/fixtur01', 'fixtur01', 1234]], $result[1]);
            $this->assertSame("session.broken-fixture\n", $result[2][0]);
            $this->assertSame($success ? "Session reset complete\n" : "Session reset failed\n", $result[2][1]);
        }
    }
}
