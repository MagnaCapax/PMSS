<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Stop transactions in scripts/lib/user/qbittorrentWebUiAuthState.php own their marker stream. */
class QbittorrentWebUiStopCleanupSafetyTest extends TestCase
{
    public function testStopTransactionClosesThroughIoAndCallbackFaults(): void
    {
        $script = <<<'PHP'
namespace QbittorrentWebUiStopCleanupFixture;
function observe($phase, $value = true) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
    return $GLOBALS['case'] === $phase.'-false' ? false : $value;
}
// Files, locks, process observations and signals are synthetic; only a memory stream is real.
function is_dir($path) { return true; }
function stat($path) { return ['uid' => 0, 'mode' => 0040700]; }
function pmssPathTargetIsSafe($path, ...$options) { return true; }
function posix_geteuid() { return 0; }
function fopen($path, $mode) {
    $GLOBALS['opened'] = [$path, $mode];
    return observe('open') ? ($GLOBALS['handle'] = \fopen('php://memory', 'w+')) : false;
}
function chmod($path, $mode) { $GLOBALS['mode'] = $mode; return observe('mode'); }
function flock($handle, $operation) { return observe($operation === LOCK_UN ? 'unlock' : 'lock'); }
function fstat($handle) { return observe('stat', ['uid' => 0, 'mode' => 0100600]); }
function rewind($handle) { return observe('rewind'); }
function stream_get_contents($handle) { return observe('read', $GLOBALS['previous']); }
function fwrite($handle, $payload) { $GLOBALS['payloads'][] = $payload; return observe('state-write', strlen($payload)); }
function ftruncate($handle, $length) { return observe('truncate'); }
function fflush($handle) { return observe('flush'); }
function pmssQbittorrentWebUiAuthPidIsNative($pid, $ops) { return observe('native'); }
function fclose($handle) { \fclose($handle); return observe('close'); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/qbittorrentWebUiAuthState.php', 'QbittorrentWebUiStopCleanupFixture');
        $script .= <<<'PHP'
$ordinary = ['success' => 'stop', 'open-false' => 'state-failed', 'lock-false' => 'state-failed',
    'stat-false' => 'state-failed', 'state-write-false' => 'state-failed', 'config-false' => 'write-failed',
    'native-false' => 'stop-failed', 'signal-false' => 'stop-failed', 'idle' => 'idle', 'deferred' => 'deferred'];
$cases = array_merge(array_keys($ordinary), ['mode', 'lock', 'stat', 'rewind', 'read', 'state-write',
    'truncate', 'flush', 'config', 'native', 'signal', 'unlock', 'close', 'config+close', 'unlock+close']);
$rows = [];
foreach ($cases as $case) {
    foreach ([\RuntimeException::class, \Error::class] as $kind) {
        $GLOBALS['case'] = $case;
        $GLOBALS['handle'] = $GLOBALS['opened'] = $GLOBALS['mode'] = null;
        $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['payloads'] = [];
        $GLOBALS['previous'] = $case === 'deferred' ? "99999\n1\nprior\n" : "0\n0\nprior\n";
        if (!isset($ordinary[$case])) foreach (explode('+', $case) as $phase) $GLOBALS['faults'][$phase] = new $kind($phase);
        $ops = ['stateDir' => '/fixture/state', 'now' => 100000,
            'signal' => static function ($pid) { return observe('signal'); }];
        $result = $caught = null;
        try {
            $result = pmssQbittorrentWebUiAuthStop('fixtur01', $case === 'idle' ? [] : [123], true, $ops,
                static function () { return observe('config'); });
        } catch (\Throwable $error) { $caught = $error; }
        $rows[] = [$case, $result, $caught === ($GLOBALS['faults'] === [] ? null : end($GLOBALS['faults'])),
            is_resource($GLOBALS['handle']), $GLOBALS['events'], $GLOBALS['payloads'], $GLOBALS['opened'], $GLOBALS['mode']];
        // A regression must not retain even the fixture's memory stream.
        if (is_resource($GLOBALS['handle'])) \fclose($GLOBALS['handle']);
    }
}
echo json_encode(['ordinary' => $ordinary, 'rows' => $rows]);
PHP;
        $data = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(50, count($data['rows']));
        foreach ($data['rows'] as $row) {
            [$case, , , , $events, $payloads] = $row;
            $this->pmssAssertArraySubsetSame([1 => $data['ordinary'][$case] ?? null, 2 => true, 3 => false,
                6 => ['/fixture/state/qbittorrentWebui-fixtur01.state', 'c+'], 7 => $case === 'open-false' ? null : 0600,
            ], $row, $case.':');
            $this->assertSame($case === 'open-false' ? ['open'] : ['close'],
                $case === 'open-false' ? $events : array_slice($events, -1), $case);
            $this->assertSame($case === 'open-false' ? 0 : 1, count(array_keys($events, 'close', true)), $case);
            if ($case === 'success') {
                $this->assertSame(['open', 'mode', 'lock', 'stat', 'rewind', 'read', 'rewind', 'state-write',
                    'truncate', 'flush', 'config', 'native', 'signal', 'rewind', 'state-write', 'truncate',
                    'flush', 'unlock', 'close'], $events);
                $this->assertSame(["100000\n1\nprior\n", "100000\n0\nprior\n"], $payloads);
            }
            if (in_array($case, ['config-false', 'native-false', 'signal-false'], true)) {
                $this->assertSame(["100000\n1\nprior\n", "0\n0\nprior\n"], $payloads, $case);
            }
            if (in_array($case, ['idle', 'deferred'], true)) {
                $this->assertFalse(in_array('native', $events, true), $case);
                $this->assertSame([$case === 'idle' ? "0\n0\nprior\n" : "99999\n1\nprior\n"], $payloads, $case);
            }
        }
    }
}
