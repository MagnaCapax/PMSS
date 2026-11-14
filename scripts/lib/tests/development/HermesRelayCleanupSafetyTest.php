<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/pmssHermesRelay.php';

/** Synchronous relay stream ownership; no sockets, account files or processes are used. */
class HermesRelayCleanupSafetyTest extends TestCase
{
    public function testAcquiredStreamsCloseOnInspectionAndExchangeFaults(): void
    {
        foreach (['connect', 'ready', 'claim', 'login'] as $operation) {
            $script = $this->fixture($operation);
            $traces = [
                'connect' => ['owner-1', 'open', 'owner-2'],
                'ready' => ['open', 'timeout', 'write', 'read', 'close'],
                'claim' => ['open', 'stat', 'read', 'close', 'unlink'],
                'login' => ['owner-1', 'open', 'owner-2', 'encode', 'timeout', 'write', 'eof', 'read', 'eof', 'close'],
            ];
            // Keep legacy false/short results as well as successful ownership transfer.
            $cases = ['success', 'open-false'];
            if ($operation === 'connect') $cases[] = 'owner-false';
            if ($operation === 'ready') $cases = array_merge($cases, ['read-false', 'short-write', 'status-200']);
            if ($operation === 'claim') $cases = array_merge($cases, ['read-false', 'stat-false', 'unlink-false']);
            if ($operation === 'login') $cases = array_merge($cases, ['read-false', 'short-write', 'empty-read']);
            foreach ($cases as $case) {
                $row = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_RELAY' => json_encode([$case, '', '', false])]);
                $this->assertSame(true, $row['sameFailure'], $operation.': '.$case);
                $this->assertSame($operation === 'connect' && $case === 'success', $row['live'], $operation.': '.$case);
                $this->assertSame($this->expectedResult($operation, $case), $row['result'], $operation.': '.$case);
                if ($case === 'success') $this->assertSame($traces[$operation], $row['events']);
                if ($operation === 'claim' && $case !== 'success' && $case !== 'unlink-false') {
                    $this->assertFalse(in_array('unlink', $row['events'], true), 'failed inspection must not spend a token');
                }
                if ($operation === 'ready' && $case !== 'open-false') {
                    $this->assertSame(["GET /api/auth/me HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n"], $row['writes']);
                }
                if ($operation === 'login' && $case !== 'open-false') {
                    $body = json_encode(['provider' => 'basic', 'username' => 'fixtur01', 'password' => 'fixture-service-secret',
                        'next' => '/user-fixtur01/hermes/']);
                    $this->assertSame(["POST /auth/password-login HTTP/1.1\r\nHost: 127.0.0.1\r\nX-Forwarded-Proto: https\r\n"
                        ."X-Forwarded-Prefix: /user-fixtur01/hermes\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)
                        ."\r\nConnection: close\r\n\r\n".$body], $row['writes']);
                }
            }
            $phases = array_unique($traces[$operation]);
            if ($operation === 'login') $phases[] = 'metadata';
            foreach ($phases as $phase) {
                foreach (['RuntimeException', 'Error'] as $kind) {
                    foreach ([false, true] as $closeFault) {
                        $case = $phase === 'metadata' ? 'empty-read' : 'success';
                        $row = $this->pmssRunInlinePhpJson($script, [
                            'PMSS_TEST_RELAY' => json_encode([$case, $phase, $kind, $closeFault]),
                        ]);
                        $trace = $phase === 'metadata'
                            ? ['owner-1', 'open', 'owner-2', 'encode', 'timeout', 'write', 'eof', 'read', 'metadata']
                            : array_slice($traces[$operation], 0, array_search($phase, $traces[$operation], true) + 1);
                        // A close fault prevents later token unlinking from being reached.
                        if ($closeFault && in_array('close', $trace, true)) $trace = array_slice($trace, 0, array_search('close', $trace, true) + 1);
                        if (in_array('open', $trace, true) && !in_array($phase, ['open', 'close', 'unlink'], true)) $trace[] = 'close';
                        $this->assertSame([null, true, false, $trace],
                            [$row['result'], $row['sameFailure'], $row['live'], $row['events']], $operation.': '.$phase.': '.$kind);
                    }
                }
            }
        }
    }

    /** Fixture-native operations expose only synthetic bytes and memory-stream lifetime. */
    private function fixture(string $operation): string
    {
        $script = <<<'PHP'
namespace HermesCleanupFixture;
function observe($phase, $value) {
    $GLOBALS['events'][] = $phase;
    if ($phase === 'close' && $GLOBALS['closeFault']) throw $GLOBALS['closeFailure'];
    if ($GLOBALS['phase'] === $phase) throw $GLOBALS['failure'];
    return $value;
}
function pmssHermesRelayListenerOwned($port, $owner = null) {
    return observe('owner-'.(++$GLOBALS['owners']), $GLOBALS['case'] !== 'owner-false' || $GLOBALS['owners'] === 1);
}
function stream_socket_client($address, &$errno, &$error, $timeout) { return fopen($address, 'socket'); }
function fopen($path, $mode) {
    observe('open', null);
    return $GLOBALS['handle'] = $GLOBALS['case'] === 'open-false' ? false : \fopen('php://memory', 'w+');
}
function fclose($handle) { \fclose($handle); return observe('close', true); }
function stream_set_timeout($handle, $seconds) { return observe('timeout', true); }
function fwrite($handle, $data) {
    $GLOBALS['writes'][] = $data;
    return observe('write', $GLOBALS['case'] === 'short-write' ? 0 : strlen($data));
}
function fgets($handle) { return observe('read', $GLOBALS['case'] === 'read-false' ? false
    : ($GLOBALS['case'] === 'status-200' ? "HTTP/1.1 200 OK\r\n" : "HTTP/1.1 401 Unauthorized\r\n")); }
function fread($handle, $size) {
    ++$GLOBALS['reads'];
    return observe('read', $GLOBALS['case'] === 'read-false' ? false : ($GLOBALS['case'] === 'empty-read' ? ''
        : ($GLOBALS['operation'] === 'claim' ? str_repeat('a', 64)."\n" : $GLOBALS['response'])));
}
function feof($handle) { return observe('eof', $GLOBALS['reads'] > 0); }
function stream_get_meta_data($handle) { return observe('metadata', ['timed_out' => true]); }
function json_encode($value) { return observe('encode', \json_encode($value)); }
function posix_geteuid() { return 1001; }
function time() { return 1000; }
function clearstatcache() {}
function lstat($path) { return ['mode' => 0700 | (substr($path, -7) === '/tokens' ? 0040000 : 0100000),
    'uid' => 1001, 'nlink' => 1, 'size' => 65, 'mtime' => 999, 'dev' => 1, 'ino' => 2]; }
function fstat($handle) { return observe('stat', $GLOBALS['case'] === 'stat-false' ? false : lstat('/fixture/token')); }
function unlink($path) { return observe('unlink', $GLOBALS['case'] !== 'unlink-false'); }
function pmssHermesRelayPassword($path) { return 'fixture-service-secret'; }
PHP;
        if ($operation !== 'claim') $script .= 'function pmssHermesRelayTokenClaim($directory, $token) { return true; }';
        foreach (array_merge(['pmssHermesRelayConnect', 'pmssHermesRelayReady', 'pmssHermesRelayLoginCookies', 'pmssHermesRelayLogin'],
            $operation === 'claim' ? ['pmssHermesRelayTokenClaim'] : []) as $function) {
            $script .= \pmssTestingFunctionExport($function, 'HermesCleanupFixture');
        }
        $script .= '$GLOBALS["operation"] = '.var_export($operation, true).';';
        $script .= <<<'PHP'
[$GLOBALS['case'], $GLOBALS['phase'], $kind, $GLOBALS['closeFault']] = \json_decode(getenv('PMSS_TEST_RELAY'), true);
$GLOBALS['events'] = $GLOBALS['writes'] = []; $GLOBALS['handle'] = null; $GLOBALS['owners'] = $GLOBALS['reads'] = 0;
$GLOBALS['failure'] = $kind === '' ? null : new $kind('operation fault');
$GLOBALS['closeFailure'] = $kind === '' ? null : new $kind('close fault');
$GLOBALS['response'] = "HTTP/1.1 200 OK\r\nSet-Cookie: __Secure-hermes_session_at=fixture; Path=/user-fixtur01/hermes; Secure; HttpOnly\r\n\r\n";
$result = $caught = null;
try {
    switch ($GLOBALS['operation']) {
        case 'connect': $result = pmssHermesRelayConnect(24000); if (is_resource($result)) $result = 'stream'; break;
        case 'ready': $result = pmssHermesRelayReady('/fixture/socket'); break;
        case 'claim': $result = pmssHermesRelayTokenClaim('/fixture/tokens', str_repeat('a', 64)); break;
        case 'login': $result = pmssHermesRelayLogin('fixture', '/fixture/.hermes/credentials', 'fixtur01', 24000); break;
    }
} catch (\Throwable $error) { $caught = $error; }
$expected = $GLOBALS['closeFault'] && in_array('close', $GLOBALS['events'], true) ? $GLOBALS['closeFailure'] : $GLOBALS['failure'];
$live = is_resource($GLOBALS['handle']);
if ($live) \fclose($GLOBALS['handle']); // Observe leaks or transferred ownership before fixture cleanup.
echo \json_encode(['result' => $result, 'sameFailure' => $caught === $expected, 'live' => $live,
    'events' => $GLOBALS['events'], 'writes' => $GLOBALS['writes']]);
PHP;
        return $script;
    }

    private function expectedResult(string $operation, string $case)
    {
        if ($operation === 'connect') return $case === 'success' ? 'stream' : false;
        if ($operation === 'ready') return !in_array($case, ['open-false', 'read-false', 'status-200'], true);
        if ($operation === 'claim') return $case === 'success';
        return $case === 'success' ? "HTTP/1.1 204 No Content\r\nSet-Cookie: __Secure-hermes_session_at=fixture; Path=/user-fixtur01/hermes; Secure; HttpOnly\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n"
            : "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n";
    }
}
