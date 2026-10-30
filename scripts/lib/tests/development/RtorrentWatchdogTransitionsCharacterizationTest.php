<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Lock transition side effects without opening sockets, launching commands or signaling processes. */
final class RtorrentWatchdogTransitionsCharacterizationTest extends TestCase
{
    private function aliveTrace(array $case): array
    {
        $script = <<<'PHP'
namespace RtorrentTransitionFixture;
function record($event, ...$args) { $GLOBALS['events'][] = array_merge([$event], $args); }
function time() { return 1000; }
function is_file($path) { return false; }
function rtorrentScgiSocketPath($user) { return '/socket'; }
function rtorrentProcessRestartMarkerPath($user) { return '/restart'; }
function rtorrentScgiCall(...$args) { record('probe', ...$args); return $GLOBALS['case']['healthy'] ?? false; }
function pmssUserWatchdogProcessPids($user, $pattern) {
    $pids = array_shift($GLOBALS['case']['pids']); record('pids', $pids); return $pids;
}
function rtorrentProcessCheckStaleState($path, $grace) {
    record('stale', $path, $grace); return ['action' => $GLOBALS['case']['stale'] ?? 'stale', 'age' => 99];
}
function rtorrentProcessStatesForPids($pids) { record('states', $pids); return $GLOBALS['case']['states'] ?? [44 => []]; }
function rtorrentScgiSocketQueueSnapshot($socket) { record('queue', $socket); return ['recvQ' => 100, 'sendQ' => 100]; }
function rtorrentProcessNormalizePids($pids) { return $pids; }
function rtorrentProcessStatesHaveUninterruptibleIo($states) { return $GLOBALS['case']['dstate'] ?? false; }
function rtorrentScgiSocketQueueSaturated($queue) { return $GLOBALS['case']['saturated'] ?? true; }
function rtorrentProcessCheckFailureCountState($path, $cycles) {
    record('wedge', $path, $cycles); return ['action' => $GLOBALS['case']['wedge'] ?? 'stale', 'count' => 2];
}
function rtorrentProcessClearStaleState($path) { record('clear', $path); }
function rtorrentProcessWriteStateFile($path, $value) { record('write', $path, $value); return $GLOBALS['case']['write'] ?? true; }
function pmssCheckRtorrentApplyThrottle(...$args) { record('throttle', ...$args); }
function pmssCheckRtorrentLog(...$args) { record('log', ...$args); }
function pmssCheckRtorrentLogBoth(...$args) { record('both', ...$args); }
function pmssCheckRtorrentCleanupStaleSocket(...$args) { record('cleanup', ...$args); }
function rtorrentProcessStart($user, $callback, $marker) { record('start', $user, $marker); return 1; }
function rtorrentProcessRestart($user, $pids, $executors, $callback, $debug) { record('restart', $user, $pids, $executors, $debug); return 1; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/rtorrent/watchdogScgi.php', 'RtorrentTransitionFixture');
        $script .= <<<'PHP'
$GLOBALS['case'] = json_decode(getenv('PMSS_TEST_TRANSITION'), true);
$GLOBALS['events'] = [];
$result = pmssCheckRtorrentHandleAliveProcess('fixtur01', [77], [
    'unresponsive' => 'unresponsive', 'acceptQueueWedge' => 'wedge', 'startMarker' => 'started',
], static function () {}, false, 120, 3);
echo json_encode([$result, $GLOBALS['events']]);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_TRANSITION' => json_encode($case)]);
    }

    public function testHealthyAndDisappearedProcessesKeepEarlyReturnOrder(): void
    {
        $probe = ['probe', '/socket', 'system.api_version', [], 5];
        $this->assertSame([null, [$probe, ['clear', 'unresponsive'], ['clear', 'wedge'],
            ['throttle', 'fixtur01', '/socket', false], ['log', 'rTorrent healthy for fixtur01', false, false]]],
            $this->aliveTrace(['healthy' => true]));
        $this->assertSame([null, [$probe, ['pids', []], ['cleanup', 'fixtur01', '/socket', 'unresponsive', false],
            ['both', 'fixtur01', 'rTorrent missing after SCGI probe; starting', false], ['start', 'fixtur01', 'started']]],
            $this->aliveTrace(['pids' => [[]]]));
    }

    public function testInitialGraceStatesNeverInspectQueueOrRestart(): void
    {
        foreach (['record', 'wait'] as $action) {
            $last = $action === 'record'
                ? ['both', 'fixtur01', 'SCGI unresponsive (socket=/socket); observing', false]
                : ['log', 'SCGI still unresponsive for fixtur01; waiting (age=99s, grace=120s)', false, false];
            $this->assertSame([null, [['probe', '/socket', 'system.api_version', [], 5], ['pids', [44]],
                ['stale', 'unresponsive', 120], $last]], $this->aliveTrace(['pids' => [[44]], 'stale' => $action]));
        }
    }

    public function testPendingDecisionsKeepGraceWritesAndDistinctWedgeCleanup(): void
    {
        foreach ([
            [['states' => []], 'SCGI unresponsive but rtorrent process state is unavailable (pids=44); extending grace', false],
            [['dstate' => true], 'SCGI unresponsive but rtorrent is in uninterruptible I/O state (pids=44); extending grace', false],
            [['saturated' => false], 'SCGI unresponsive but rtorrent still alive (pids=44; recvQ=100 sendQ=100); extending grace', false],
            [['wedge' => 'wait'], 'SCGI accept queue saturated for rtorrent (pids=44; recvQ=100 sendQ=100; count=2/3); observing', true],
        ] as [$case, $message, $observing]) {
            foreach ([true, false] as $writeResult) {
                $events = [['probe', '/socket', 'system.api_version', [], 5], ['pids', [44]],
                    ['stale', 'unresponsive', 120], ['pids', [44]], ['states', [44]], ['queue', '/socket']];
                if ($observing) $events[] = ['wedge', 'wedge', 3];
                $events[] = ['write', 'unresponsive', '1000'];
                if (!$observing) $events[] = ['clear', 'wedge'];
                $events[] = ['both', 'fixtur01', $message, false];
                $this->assertSame([null, $events], $this->aliveTrace($case + ['pids' => [[44], [44]], 'write' => $writeResult]));
            }
        }
    }

    public function testRestartCompletionKeepsDifferentCleanupForWedgeAndLostProcess(): void
    {
        $prefix = [['probe', '/socket', 'system.api_version', [], 5], ['pids', [44]], ['stale', 'unresponsive', 120]];
        $this->assertSame([null, array_merge($prefix, [['pids', [44]], ['states', [44]], ['queue', '/socket'],
            ['wedge', 'wedge', 3], ['both', 'fixtur01', 'SCGI accept queue saturated for consecutive checks (pids=44; recvQ=100 sendQ=100); restarting rtorrent', false],
            ['restart', 'fixtur01', [44], [77], false], ['clear', 'unresponsive'], ['clear', 'wedge']])],
            $this->aliveTrace(['pids' => [[44], [44]]]));
        $this->assertSame([null, array_merge($prefix, [['pids', []], ['cleanup', 'fixtur01', '/socket', 'unresponsive', false],
            ['both', 'fixtur01', 'SCGI unresponsive for 99s; restarting rtorrent', false],
            ['restart', 'fixtur01', [], [77], false], ['clear', 'unresponsive']])],
            $this->aliveTrace(['pids' => [[44], []]]));
    }

    public function testResolvedMarkerPolicyKeepsRemovalOrderAndMissingKeyBehavior(): void
    {
        $script = <<<'PHP'
namespace RtorrentResolvedFixture;
function pmssPathTargetIsSafe(...$args) { return true; }
function is_file($path) { return true; }
function unlink($path) { $GLOBALS['removed'][] = $path; return true; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/rtorrent/watchdogState.php', 'RtorrentResolvedFixture');
        $script .= <<<'PHP'
[$present, $executor, $state] = json_decode(getenv('PMSS_TEST_RESOLVED'), true);
$GLOBALS['removed'] = [];
rtorrentProcessClearResolvedWatchdogState($state, $present, $executor);
echo json_encode($GLOBALS['removed']);
PHP;
        $keys = ['missing', 'acceptQueueWedge', 'startMarker', 'startFailure', 'sessionReset', 'escalation'];
        foreach ([
            [false, false, ['missing', 'acceptQueueWedge']],
            [false, true, ['acceptQueueWedge', 'startMarker', 'startFailure', 'sessionReset', 'escalation']],
            [true, false, ['missing', 'startMarker', 'startFailure', 'sessionReset', 'escalation']],
            [true, true, ['missing', 'startMarker', 'startFailure', 'sessionReset', 'escalation']],
        ] as [$present, $executor, $expected]) {
            foreach ([array_combine($keys, $keys), ['missing' => null, 'escalation' => 'escalation'], []] as $state) {
                $removed = array_values(array_filter($expected, static function ($key) use ($state): bool { return isset($state[$key]); }));
                $this->assertSame($removed, $this->pmssRunInlinePhpJson($script, [
                    'PMSS_TEST_RESOLVED' => json_encode([$present, $executor, $state]),
                ]));
            }
        }
    }
}
