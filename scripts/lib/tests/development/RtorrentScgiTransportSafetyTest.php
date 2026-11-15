<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Socket fault injection uses memory streams and never opens a real connection. */
class RtorrentScgiTransportSafetyTest extends TestCase
{
    public function testMalformedPathsStopBeforeFilesystemSocketAndQueueProbes(): void
    {
        $runtime = var_export($this->pmssRepoPath('scripts/lib/runtime.php'), true);
        $script = <<<'PHP'
namespace ScgiPathInputFixture;
// Record every system boundary; these fixtures never inspect or connect to a host socket.
function file_exists($path) { $GLOBALS['events'][] = ['exists', $path]; return true; }
function filetype($path) { $GLOBALS['events'][] = ['type', $path]; return 'socket'; }
function fsockopen($endpoint, $port, &$errno, &$errstr, $timeout) {
    $GLOBALS['events'][] = ['open', $endpoint, $port, $timeout];
    return false;
}
function exec($command, &$output, &$rc) {
    $GLOBALS['events'][] = ['exec', $command];
    $output = ['u_str LISTEN 1 2 '.$GLOBALS['path'].' 12345 * 0'];
    $rc = 0;
}
PHP;
        $script .= "require {$runtime};";
        foreach (['transport.php', 'queue.php'] as $module) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/rtorrent/scgi/'.$module, 'ScgiPathInputFixture');
        }
        $paths = ['', "\0", "\0/fixture/scgi.socket", "/fixture/scgi\0.socket", "/fixture/scgi.socket\0",
            '/fixture/scgi.socket', 'relative.socket', '/fixture/slot name.socket', "/fixture/scgi.socket\n"];
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_SCGI_PATHS'), true) as $path) {
    $GLOBALS['path'] = $path;
    $GLOBALS['events'] = [];
    $sent = rtorrentScgiSend($path, "request\0bytes", 7);
    $snapshot = rtorrentScgiSocketQueueSnapshot($path);
    $results[] = [$sent, $snapshot, $GLOBALS['events']];
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_SCGI_PATHS' => json_encode($paths)]);
        $this->assertSame(count($paths), count($results));
        foreach ($paths as $index => $path) {
            $invalid = $path === '' || strpos($path, "\0") !== false;
            // Whitespace is preserved in transport arguments; queue columns retain their old splitting.
            $snapshot = $invalid || strpos($path, ' ') !== false || strpos($path, "\n") !== false
                ? null : ['recvQ' => 1, 'sendQ' => 2];
            $events = $invalid ? [] : [['exists', $path], ['type', $path],
                ['open', 'unix://'.$path, 0, 7], ['exec', 'ss -xln 2>/dev/null']];
            $this->assertSame([false, $snapshot, $events], $results[$index], var_export($path, true));
        }
    }

    public function testSocketLifetimePreservesReturnsArgumentsAndThrownObjects(): void
    {
        $script = <<<'PHP'
namespace ScgiTransportFixture;
function observe($phase, $result) {
    $GLOBALS['events'][] = $phase;
    if ($GLOBALS['phase'] === $phase) throw $GLOBALS['failure'];
    return $result;
}
function file_exists($path) { return observe('exists', $GLOBALS['case'] !== 'missing'); }
function filetype($path) { return observe('type', $GLOBALS['case'] === 'regular' ? 'file' : 'socket'); }
function fsockopen($endpoint, $port, &$errno, &$errstr, $timeout) {
    observe('open', null);
    $GLOBALS['args']['open'] = [$endpoint, $port, $timeout];
    return $GLOBALS['handle'] = $GLOBALS['case'] === 'open-failure' ? false : \fopen('php://temp', 'w+');
}
function stream_set_timeout($handle, $timeout) {
    $GLOBALS['args']['timeout'] = $timeout;
    return observe('timeout', $GLOBALS['case'] !== 'timeout-failure');
}
function fwrite($handle, $request) {
    $GLOBALS['args']['request'] = $request;
    return observe('write', ['write-failure' => false, 'short-write' => 2, 'zero-write' => 0][$GLOBALS['case']] ?? strlen($request));
}
function feof($handle) { return observe('eof', $GLOBALS['reads'] >= count($GLOBALS['chunks'])); }
function fread($handle, $length) {
    $GLOBALS['args']['lengths'][] = $length;
    return observe('read', $GLOBALS['chunks'][$GLOBALS['reads']++]);
}
function stream_get_meta_data($handle) { return observe('meta', ['timed_out' => $GLOBALS['case'] === 'timed-out']); }
function fclose($handle) { $GLOBALS['events'][] = 'close'; return \fclose($handle); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/rtorrent/scgi/transport.php', 'ScgiTransportFixture');
        $script .= <<<'PHP'
$results = [];
foreach (['empty-path', 'empty-request', 'invalid-timeout', 'missing', 'regular', 'open-failure',
    'timeout-failure', 'write-failure', 'short-write', 'zero-write', 'read-failure',
    'partial-read-failure', 'timed-out', 'empty-response', 'empty-chunk', 'success',
    'timeout', 'write', 'eof', 'read', 'meta'] as $case) {
    // Exercise both Throwable families at every operation after socket acquisition.
    $phase = in_array($case, ['timeout', 'write', 'eof', 'read', 'meta'], true) ? $case : null;
    foreach ($phase === null ? [null] : [new \RuntimeException($case), new \Error($case)] as $failure) {
        $GLOBALS['case'] = $case; $GLOBALS['events'] = []; $GLOBALS['args'] = [];
        $GLOBALS['handle'] = null; $GLOBALS['reads'] = 0;
        $GLOBALS['chunks'] = $case === 'read-failure' ? [false]
            : ($case === 'partial-read-failure' ? ['first', false]
            : ($case === 'empty-response' ? [] : [$case === 'empty-chunk' ? '' : 'first', "second\0"]));
        $caught = null; $result = null;
        try {
            $result = rtorrentScgiSend($case === 'empty-path' ? '' : '/fixture/scgi.socket',
                $case === 'empty-request' ? '' : "request\0bytes", $case === 'invalid-timeout' ? 0 : 7);
        } catch (\Throwable $error) { $caught = $error; }
        $closed = !is_resource($GLOBALS['handle']);
        if (!$closed) \fclose($GLOBALS['handle']); // Observe a leak before fixture cleanup.
        $results[] = [$case, $result, $caught === $failure, $closed, $GLOBALS['events'], $GLOBALS['args']];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $prefix = ['exists', 'type', 'open', 'timeout', 'write'];
        $read = ['eof', 'read', 'meta'];
        $cases = [
            'empty-path' => [false, []], 'empty-request' => [false, []], 'invalid-timeout' => [false, []],
            'missing' => [false, ['exists']], 'regular' => [false, ['exists', 'type']],
            'open-failure' => [false, array_slice($prefix, 0, 3)],
            'timeout-failure' => [false, array_merge(array_slice($prefix, 0, 4), ['close'])],
            'write-failure' => [false, array_merge($prefix, ['close'])],
            'short-write' => [false, array_merge($prefix, ['close'])],
            'zero-write' => [false, array_merge($prefix, ['close'])],
            'read-failure' => ['', array_merge($prefix, ['eof', 'read', 'close'])],
            'partial-read-failure' => ['first', array_merge($prefix, $read, ['eof', 'read', 'close'])],
            'timed-out' => [false, array_merge($prefix, $read, ['close'])],
            'empty-response' => ['', array_merge($prefix, ['eof', 'close'])],
            'empty-chunk' => ["second\0", array_merge($prefix, $read, $read, ['eof', 'close'])],
            'success' => ["firstsecond\0", array_merge($prefix, $read, $read, ['eof', 'close'])],
        ];
        foreach (['timeout', 'write', 'eof', 'read', 'meta'] as $phase) {
            $trace = array_merge($prefix, $read);
            $cases[$phase] = [null, array_merge(array_slice($trace, 0, array_search($phase, $trace, true) + 1), ['close'])];
        }
        $index = 0;
        foreach ($cases as $case => [$expected, $events]) {
            $args = [];
            if (in_array('open', $events, true)) $args['open'] = ['unix:///fixture/scgi.socket', 0, 7];
            if (in_array('timeout', $events, true)) $args['timeout'] = 7;
            if (in_array('write', $events, true)) $args['request'] = "request\0bytes";
            if (in_array('read', $events, true)) $args['lengths'] = array_fill(0, count(array_keys($events, 'read', true)), 4096);
            for ($kind = 0; $kind < ($expected === null ? 2 : 1); $kind++) {
                $this->assertSame([$case, $expected, true, true, $events, $args], $results[$index++], $case);
            }
        }
        $this->assertSame($index, count($results));
    }
}
