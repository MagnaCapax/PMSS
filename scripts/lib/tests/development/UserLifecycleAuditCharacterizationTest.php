<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Freeze audit payloads and web-stack observations without writing logs or starting services. */
class UserLifecycleAuditCharacterizationTest extends TestCase
{
    public function testAuditMergeOrderAndTextBytes(): void
    {
        $result = $this->auditHarness(<<<'PHP'
pmssUserLifecycleContextLogStatusMessage('suspend', 'validate', 'alice', 'ERR', 'original', [
    'status' => 'WARN', 'message' => "line\nnext", 'step' => "check\tpath", 'ts' => 'fixed',
    'event' => 'custom_event', 'username' => 'bob',
]);
pmssUserLifecycleContextLogHomeInfo('unsuspend', 'start', 'alice', '/fixture/home');
$GLOBALS['allowed'] = false;
pmssUserLifecycleContextLog('test', 'ignored', 'alice');
echo json_encode($GLOBALS['events']);
PHP
        );
        $base = [
            'event' => 'custom_event', 'action' => 'suspend', 'phase' => 'validate', 'username' => 'bob',
            'operator_user' => 'operator', 'operator_uid' => 42, 'host' => 'fixture-host',
            'ssh_connection' => 'connection', 'ssh_client' => null, 'sudo_user' => 'operator',
            'pmss_correlation_id' => 'correlation', 'argv' => ['fixture.php', 'alice'],
            'status' => 'WARN', 'message' => "line\nnext", 'step' => "check\tpath", 'ts' => 'fixed',
        ];
        $this->assertSame(['json', $base], $result[0]);
        $this->assertSame(['text', 'user=bob action=suspend phase=validate status=WARN step=check path msg=line next'], $result[1]);
        $home = array_merge(array_slice($base, 0, 12), [
            'event' => 'user_unsuspend', 'action' => 'unsuspend', 'phase' => 'start', 'username' => 'alice',
            'status' => 'INFO', 'home_dir' => '/fixture/home', 'ts' => 'fixed',
        ]);
        $this->assertSame(['json', $home], $result[2]);
        $this->assertSame(['text', 'user=alice action=unsuspend phase=start status=INFO'], $result[3]);
        $this->assertSame(4, count($result));
    }

    public function testWebStackResultAndAuditUseTheSameObservation(): void
    {
        foreach ([
            [true, 7, [], 7, null],
            [false, 7, [], 7, ['lighttpd', 'php-cgi']],
            [false, 0, ['php-cgi'], 1, ['lighttpd']],
            [false, 0, ['lighttpd'], 1, ['php-cgi']],
            [false, 0, ['lighttpd', 'php-cgi'], 0, []],
            [false, 7, ['lighttpd', 'php-cgi'], 0, []],
        ] as $case) {
            $script = '$case = '.var_export($case, true).';'.<<<'PHP'
$checks = [];
$rc = pmssUserLifecycleWebStackStartAndVerify('unsuspend', 'alice', $case[0],
    static function ($action, $user, $step, $command, $dry) use ($case): int { return $case[1]; },
    static function ($user, $process) use ($case, &$checks): bool {
        $checks[] = $process;
        return in_array($process, $case[2], true);
    });
echo json_encode(['rc' => $rc, 'checks' => $checks, 'events' => $GLOBALS['events']]);
PHP;
            $result = $this->auditHarness($script);
            $this->assertSame($case[3], $result['rc']);
            $this->assertSame($case[0] ? [] : ['lighttpd', 'php-cgi'], $result['checks']);
            if ($case[4] === null) {
                $this->assertSame([], $result['events']);
                continue;
            }
            $payload = $result['events'][0][1];
            $this->assertSame($case[1], $payload['start_rc']);
            $this->assertSame($case[4] === [] ? 'OK' : 'ERR', $payload['status']);
            $this->assertSame($case[4] === [] ? 'Per-user lighttpd and php-cgi are running' : 'Per-user web stack failed to start', $payload['message']);
            $this->assertSame($case[4] === [] ? null : $case[4], $payload['missing_processes'] ?? null);
            $this->assertSame(2, count($result['events']));
        }
    }

    private function auditHarness(string $body): array
    {
        $script = 'namespace LifecycleAuditFixture; require_once '.var_export($this->pmssRepoPath('scripts/lib/userLifecycle.php'), true).';';
        $script .= <<<'PHP'
$GLOBALS['events'] = []; $GLOBALS['allowed'] = true; $GLOBALS['argv'] = ['fixture.php', 'alice'];
function pmssUserLogAllowed() { return $GLOBALS['allowed']; }
function pmssJsonLineAppend($path, $payload) { $GLOBALS['events'][] = ['json', $payload]; }
function pmssLogAppendTimestampedLine($path, $text) { $GLOBALS['events'][] = ['text', $text]; }
function posix_geteuid() { return 42; }
function posix_getpwuid($uid) { return ['name' => 'fallback']; }
function gethostname() { return 'fixture-host'; }
function date($format) { return 'fixed'; }
function getenv($key) {
    return ['SUDO_USER' => 'operator', 'SSH_CONNECTION' => 'connection', 'PMSS_CORRELATION_ID' => 'correlation'][$key] ?? false;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/userLifecycle.php', 'LifecycleAuditFixture',
            ['user/lifecycleLog.php', 'user/lifecycleServices.php']);
        return $this->pmssRunInlinePhpJson($script.$body);
    }
}
