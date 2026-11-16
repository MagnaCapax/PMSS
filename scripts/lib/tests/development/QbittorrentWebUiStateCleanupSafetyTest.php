<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Marker readers in scripts/lib/user/qbittorrentWebUiAuthState.php own every acquired stream. */
class QbittorrentWebUiStateCleanupSafetyTest extends TestCase
{
    public function testMarkerReadersCloseThroughMetadataAndIoFaults(): void
    {
        $script = <<<'PHP'
namespace QbittorrentWebUiStateCleanupFixture;
function observe($phase, $value = true) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
    return in_array($GLOBALS['case'], [$phase.'-false', $phase.'-state-failed'], true) ? false : $value;
}
// All paths, metadata and locks are synthetic; streams contain only memory-backed fixture data.
function pmssPathTargetIsSafe($path, ...$options) { return observe('path'); }
function fopen($path, $mode) {
    $GLOBALS['opened'] = [$path, $mode];
    return observe('open') ? ($GLOBALS['handle'] = \fopen('php://memory', 'w+')) : false;
}
function flock($handle, $operation) {
    $GLOBALS['locks'][] = $operation;
    return observe($operation === LOCK_UN ? 'unlock' : 'lock');
}
function fstat($handle) {
    return observe('stat', ['uid' => $GLOBALS['case'] === 'owner-mismatch' ? 1 : 0,
        'mode' => $GLOBALS['case'] === 'mode-unsafe' ? 0100622 : 0100600]);
}
function posix_geteuid() { return observe('uid', 0); }
function stream_get_contents($handle) { return observe('read', $GLOBALS['previous']); }
function rewind($handle) { return observe('rewind'); }
function fwrite($handle, $payload) { $GLOBALS['payloads'][] = $payload; return observe('write', strlen($payload)); }
function ftruncate($handle, $length) { return observe('truncate'); }
function fflush($handle) { return observe('flush'); }
function fclose($handle) { \fclose($handle); return observe('close'); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/qbittorrentWebUiAuthState.php', 'QbittorrentWebUiStateCleanupFixture');
        $script .= <<<'PHP'
$ordinary = [
    'active' => ['success' => true, 'path-false' => false, 'open-false' => false, 'lock-false' => false,
        'read-false' => false, 'inactive' => false, 'pending' => true, 'malformed' => false, 'empty' => false,
        'unlock-false' => true, 'close-false' => true],
    'log' => ['success' => true, 'path-false' => false, 'path-state-failed' => true, 'open-false' => false,
        'open-state-failed' => true, 'lock-false' => false, 'lock-state-failed' => true, 'stat-false' => false,
        'stat-state-failed' => true, 'owner-mismatch' => false, 'mode-unsafe' => false, 'repeat' => false,
        'stop' => true, 'read-false' => true, 'write-false' => false, 'truncate-false' => false,
        'flush-false' => false, 'unlock-false' => true, 'close-false' => true],
];
$rows = [];
foreach ($ordinary as $reader => $results) {
    $phases = $reader === 'active' ? ['path', 'open', 'lock', 'read', 'unlock', 'close']
        : ['path', 'open', 'lock', 'stat', 'uid', 'read', 'rewind', 'write', 'truncate', 'flush', 'unlock', 'close'];
    $cases = array_merge(array_keys($results), $phases, ['lock+close', 'read+close', 'unlock+close']);
    foreach ($cases as $case) foreach ([\RuntimeException::class, \Error::class] as $kind) {
        $GLOBALS['case'] = $case;
        $GLOBALS['handle'] = $GLOBALS['opened'] = null;
        $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['payloads'] = $GLOBALS['locks'] = [];
        $GLOBALS['previous'] = ['inactive' => "0\n0\nprior\n", 'pending' => "0\n1\nprior\n",
            'malformed' => "oops\n1\nprior\n", 'empty' => ''][$case] ?? "100000\n1\nprior\n";
        if (!array_key_exists($case, $results)) {
            foreach (explode('+', $case) as $phase) $GLOBALS['faults'][$phase] = new $kind($phase);
        }
        $status = substr($case, -13) === '-state-failed' ? 'state-failed' : ($case === 'repeat' ? 'prior' : ($case === 'stop' ? 'stop' : 'deferred'));
        $result = $caught = null;
        try {
            $ops = ['stateDir' => '/fixture/state'];
            $result = $reader === 'active' ? pmssQbittorrentWebUiAuthStateActive('fixtur01', $ops)
                : pmssQbittorrentWebUiAuthStateShouldLog('fixtur01', $status, $ops);
        } catch (\Throwable $error) { $caught = $error; }
        $rows[] = [$reader, $case, $result, $caught === ($GLOBALS['faults'] === [] ? null : end($GLOBALS['faults'])),
            is_resource($GLOBALS['handle']), $GLOBALS['events'], $GLOBALS['payloads'], $GLOBALS['opened'], $GLOBALS['locks']];
        // Release even a leaked fixture stream before continuing the matrix.
        if (is_resource($GLOBALS['handle'])) \fclose($GLOBALS['handle']);
    }
}
echo json_encode(['ordinary' => $ordinary, 'rows' => $rows]);
PHP;
        $data = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(108, count($data['rows']));
        foreach ($data['rows'] as $row) {
            [$reader, $case, , , , $events, $payloads, $opened, $locks] = $row;
            $label = $reader.':'.$case;
            $this->pmssAssertArraySubsetSame([2 => $data['ordinary'][$reader][$case] ?? null, 3 => true, 4 => false], $row, $label);
            $acquired = !in_array($case, ['path', 'path-false', 'path-state-failed', 'open', 'open-false', 'open-state-failed'], true);
            $this->assertSame($acquired ? 1 : 0, count(array_keys($events, 'close', true)), $label);
            if ($acquired) $this->assertSame('close', end($events), $label);
            $this->assertSame(strpos($case, 'path') === 0 ? null
                : ['/fixture/state/qbittorrentWebui-fixtur01.state', $reader === 'active' ? 'r' : 'r+'], $opened, $label);
            if ($locks !== []) $this->assertSame($reader === 'active' ? LOCK_SH : LOCK_EX, $locks[0], $label);
            if ($reader === 'active') $this->assertSame([], $payloads, $label);
            if ($case === 'success') {
                $this->assertSame($reader === 'active' ? ['path', 'open', 'lock', 'read', 'unlock', 'close']
                    : ['path', 'open', 'lock', 'stat', 'uid', 'read', 'rewind', 'write', 'truncate', 'flush', 'unlock', 'close'], $events, $label);
                $this->assertSame($reader === 'active' ? [] : ["100000\n1\ndeferred\n"], $payloads, $label);
                $this->assertSame([$reader === 'active' ? LOCK_SH : LOCK_EX, LOCK_UN], $locks, $label);
            }
            if ($reader === 'log' && in_array($case, ['repeat', 'stop', 'read-false'], true)) {
                $this->assertSame([$case === 'read-false' ? "\n0\ndeferred\n" : "100000\n1\n".($case === 'stop' ? 'stop' : 'prior')."\n"], $payloads, $label);
            }
            if (in_array($case, ['lock-false', 'lock-state-failed', 'stat-false', 'stat-state-failed', 'owner-mismatch', 'mode-unsafe'], true)) {
                $this->assertSame([], $payloads, $label);
            }
        }
    }
}
