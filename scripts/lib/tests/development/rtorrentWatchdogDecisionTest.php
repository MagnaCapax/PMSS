<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/rtorrent/watchdog.php';

class rtorrentWatchdogDecisionTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-rtorrent-watchdog-');
    }

    private function wedgeStatePath(): string
    {
        return $this->tempDir.'/accept-queue.count';
    }

    private function assertScgiDecisionExtendsGrace(
        array $pids,
        array $processStates,
        ?array $queueSnapshot,
        string $messageNeedle,
        string $label
    ): void {
        $decision = \rtorrentProcessScgiUnresponsiveDecision($pids, $processStates, $queueSnapshot, $this->wedgeStatePath(), 3);

        $this->assertSame('extend_grace', $decision['action'], $label);
        $this->assertStringContainsString($messageNeedle, $decision['message'], $label);
        $this->assertFalse(file_exists($this->wedgeStatePath()), $label.' must not advance queue wedge count');
    }

    private function writeWatchdogStateFiles(array $state): void
    {
        foreach ($state as $path) {
            file_put_contents($path, '1');
        }
    }

    private function escalatedStatePaths(): array
    {
        return \rtorrentProcessWatchdogStatePaths($this->tempDir, 'alice');
    }

    public function testCronLogHonorsForceAndDebugFlags(): void
    {
        $this->assertSame('', $this->pmssCaptureStdout(static function (): void {
            \pmssCheckRtorrentLog('hidden', false, false);
        })[1]);

        foreach ([[true, false], [false, true]] as $case) {
            $output = $this->pmssCaptureStdout(static function () use ($case): void {
                \pmssCheckRtorrentLog('visible', $case[0], $case[1]);
            })[1];
            $this->assertStringContainsString(' visible', $output);
        }
    }

    public function testWatchdogStatePathsPreserveExistingFileNames(): void
    {
        $paths = \rtorrentProcessWatchdogStatePaths('/run/pmss/', 'alice');

        $this->assertEquals([
            'missing' => '/run/pmss/checkRtorrent-missing-alice.ts',
            'unresponsive' => '/run/pmss/checkRtorrent-unresponsive-alice.ts',
            'acceptQueueWedge' => '/run/pmss/checkRtorrent-accept-queue-alice.count',
            'startMarker' => '/run/pmss/checkRtorrent-started-alice.ts',
            'startFailure' => '/run/pmss/checkRtorrent-start-failure-alice.count',
            'sessionReset' => '/run/pmss/checkRtorrent-session-reset-alice.ts',
            'escalation' => '/run/pmss/checkRtorrent-escalated-alice.flag',
        ], $paths);
    }

    public function testClearResolvedWatchdogStatePreservesExistingMarkerSemantics(): void
    {
        $state = \rtorrentProcessWatchdogStatePaths($this->tempDir, 'alice');
        $this->writeWatchdogStateFiles($state);

        \rtorrentProcessClearResolvedWatchdogState($state, true, false);
        $this->assertFalse(file_exists($state['missing']), 'missing marker clears once rtorrent is present');
        $this->assertTrue(file_exists($state['acceptQueueWedge']), 'queue wedge marker remains while rtorrent is present');
        foreach (['startMarker', 'startFailure', 'sessionReset', 'escalation'] as $key) {
            $this->assertFalse(file_exists($state[$key]), $key.' clears once start path has resolved');
        }

        $this->writeWatchdogStateFiles($state);
        \rtorrentProcessClearResolvedWatchdogState($state, false, false);
        $this->assertFalse(file_exists($state['missing']), 'missing marker clears when no executor remains');
        $this->assertFalse(file_exists($state['acceptQueueWedge']), 'queue wedge marker clears when rtorrent is gone');
        foreach (['startMarker', 'startFailure', 'sessionReset', 'escalation'] as $key) {
            $this->assertTrue(file_exists($state[$key]), $key.' remains until a process or executor appears');
        }
    }

    public function testStateWritesRequireCompletePayloads(): void
    {
        // Inject write results in a child process without touching live marker files.
        $script = <<<'PHP'
namespace WatchdogStateWriteFixture;
function file_put_contents($path, $data, $flags) {
    $GLOBALS['requests'][] = [$path, $data, $flags];
    $limit = $GLOBALS['limit'];
    if ($limit === false) return false;
    return \file_put_contents($path, $limit === null ? $data : substr($data, 0, $limit), $flags);
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/rtorrent/watchdogState.php', 'WatchdogStateWriteFixture');
        $script .= <<<'PHP'
[$path, $payload, $GLOBALS['limit'], $escalation] = json_decode(getenv('PMSS_TEST_STATE_WRITE'), true);
$GLOBALS['requests'] = [];
$result = $escalation
    ? rtorrentProcessWriteEscalationState($path, 'alice', 6, 900)
    : rtorrentProcessWriteStateFile($path, $payload);
echo json_encode([$result, file_get_contents($path), $GLOBALS['requests']]);
PHP;
        $path = $this->tempDir.'/write-marker';
        foreach (['', '0', '1700000000', "binary\0payload", '{"timestamp":900,"user":"alice","count":6}'] as $payload) {
            $escalation = substr($payload, 0, 1) === '{';
            foreach ([false, 0, 1, max(0, strlen($payload) - 1), strlen($payload), null] as $limit) {
                file_put_contents($path, 'prior marker');
                $bytes = $limit === false ? 'prior marker' : ($limit === null ? $payload : substr($payload, 0, $limit));
                $complete = $limit !== false && ($limit === null || $limit >= strlen($payload));
                $this->assertSame([$complete, $bytes, [[$path, $payload, LOCK_EX]]],
                    $this->pmssRunInlinePhpJson($script, [
                        'PMSS_TEST_STATE_WRITE' => json_encode([$path, $payload, $limit, $escalation]),
                    ]));
            }
        }
    }

    public function testEscalationEncodingFailurePreservesExistingAndAbsentMarkers(): void
    {
        $path = $this->escalatedStatePaths()['escalation'];
        $original = '{"timestamp":900,"user":"alice","count":6}';
        // Invalid UTF-8 at each position and truncated/overlong sequences fail encoding.
        foreach (["\xffalice", "ali\xffce", "alice\xff", "\xc3", "\xc0\xaf"] as $user) {
            file_put_contents($path, $original);
            $this->assertFalse(\rtorrentProcessWriteEscalationState($path, $user, 7, 1000));
            $this->assertSame($original, file_get_contents($path));
            $this->assertSame(['action' => 'wait', 'age' => 100], \rtorrentProcessEscalationRetryState($path, 180, 1000));

            unlink($path);
            $this->assertFalse(\rtorrentProcessWriteEscalationState($path, $user, 7, 1000));
            $this->assertFalse(file_exists($path));
        }
    }

    public function testEscalationEncodingPreservesLegacyBytes(): void
    {
        $path = $this->escalatedStatePaths()['escalation'];
        foreach ([
            ['alice', 'alice'],
            ['', ''],
            ['a/b', 'a\\/b'],
            ["a\n", 'a\\n'],
            ["\xc3\xa4", '\\u00e4'],
        ] as $case) {
            $this->assertTrue(\rtorrentProcessWriteEscalationState($path, $case[0], 6, 900));
            $this->assertSame('{"timestamp":900,"user":"'.$case[1].'","count":6}', file_get_contents($path));
        }
        $this->assertFalse(\rtorrentProcessWriteEscalationState($this->tempDir.'/missing/marker', 'alice', 6, 900));
    }

    public function testEscalationRetryStateUsesMarkerTimestamp(): void
    {
        $state = $this->escalatedStatePaths();

        $this->assertSame(['action' => 'record', 'age' => 0], \rtorrentProcessEscalationRetryState($state['escalation'], 180, 1000));

        \rtorrentProcessWriteEscalationState($state['escalation'], 'alice', 6, 900);
        $this->assertSame(['action' => 'wait', 'age' => 99], \rtorrentProcessEscalationRetryState($state['escalation'], 180, 999));
        $this->assertSame(['action' => 'retry', 'age' => 180], \rtorrentProcessEscalationRetryState($state['escalation'], 180, 1080));

        file_put_contents($state['escalation'], '500');
        $this->assertSame(['action' => 'retry', 'age' => 500], \rtorrentProcessEscalationRetryState($state['escalation'], 180, 1000));

        file_put_contents($state['escalation'], 'not-json');
        $this->assertSame(['action' => 'record', 'age' => 0], \rtorrentProcessEscalationRetryState($state['escalation'], 180, 1000));
    }

    public function testEscalatedStartFailureRetriesOnceAfterBoundedIntervalAndClearsOnSuccess(): void
    {
        $state = $this->escalatedStatePaths();
        \rtorrentProcessWriteEscalationState($state['escalation'], 'alice', 6, time() - 3600);
        $attempts = 0;
        $logger = static function (string $message, bool $force = false): void {};

        $this->pmssCaptureStdout(static function () use ($state, $logger, &$attempts): void {
            \pmssCheckRtorrentHandleEscalatedStartFailure(
                'alice',
                $state,
                6,
                180,
                $logger,
                false,
                static function (string $user, callable $logCallback, string $startMarker) use (&$attempts): int {
                    $attempts++;
                    file_put_contents($startMarker, 'started');
                    return 0;
                }
            );
        });

        $this->assertSame(1, $attempts, 'elapsed escalation interval should permit exactly one start attempt');
        $this->assertFalse(file_exists($state['escalation']), 'successful bounded retry clears escalation flag');
        $this->assertSame('started', (string) file_get_contents($state['startMarker']));
    }

    public function testEscalatedStartFailureWaitsBeforeBoundedInterval(): void
    {
        $state = $this->escalatedStatePaths();
        \rtorrentProcessWriteEscalationState($state['escalation'], 'alice', 6, time());
        $before = (string) file_get_contents($state['escalation']);
        $attempts = 0;
        $logger = static function (string $message, bool $force = false): void {};

        $this->pmssCaptureStdout(static function () use ($state, $logger, &$attempts): void {
            \pmssCheckRtorrentHandleEscalatedStartFailure(
                'alice',
                $state,
                6,
                3600,
                $logger,
                false,
                static function (string $user, callable $logCallback, string $startMarker) use (&$attempts): int {
                    $attempts++;
                    return 0;
                }
            );
        });

        $this->assertSame(0, $attempts, 'fresh escalation interval must not attempt a start');
        $this->assertSame($before, (string) file_get_contents($state['escalation']), 'waiting must not re-arm the interval');
        $this->assertFalse(file_exists($state['startMarker']), 'waiting must not touch the start marker');
    }

    public function testEscalatedStartFailureRearmsAfterFailedOneShot(): void
    {
        $state = $this->escalatedStatePaths();
        \rtorrentProcessWriteEscalationState($state['escalation'], 'alice', 6, time() - 3600);
        $before = time();
        $attempts = 0;
        $logger = static function (string $message, bool $force = false): void {};

        $this->pmssCaptureStdout(static function () use ($state, $logger, &$attempts): void {
            \pmssCheckRtorrentHandleEscalatedStartFailure(
                'alice',
                $state,
                7,
                180,
                $logger,
                false,
                static function (string $user, callable $logCallback, string $startMarker) use (&$attempts): int {
                    $attempts++;
                    return 1;
                }
            );
        });

        $payload = json_decode((string) file_get_contents($state['escalation']), true);
        $this->assertSame(1, $attempts, 'failed one-shot still attempts only once');
        $this->assertTrue(is_array($payload), 'failed one-shot should leave a JSON escalation marker');
        $this->assertSame('alice', $payload['user']);
        $this->assertSame(7, $payload['count']);
        $this->assertTrue((int) $payload['timestamp'] >= $before, 'failed one-shot should re-arm the interval from now');
    }

    public function testGraceStateUsesBaseAndExtendsAfterRecentRestartMarkers(): void
    {
        $marker = $this->tempDir.'/restart-marker';

        foreach ([
            [null, 1000, 120, 0],
            ['900', 1000, 600, 100],
            ['1000', 9000, 1200, 8000],
            ['1', 20001, 120, 20000],
        ] as $case) {
            if ($case[0] === null) {
                @unlink($marker);
            } else {
                file_put_contents($marker, $case[0]);
            }
            $this->assertEquals(['grace' => $case[2], 'restartAge' => $case[3]], \rtorrentProcessUnresponsiveGraceState($marker, 120, $case[1]));
        }
    }

    public function testScgiDecisionExtendsGraceForUnsafeStatesOrOpenQueue(): void
    {
        $aliveStates = [44 => ['pid' => 44, 'stat' => 'Sl', 'wchan' => 'poll_schedule_timeout']];

        foreach ([
            'missing process state' => [[44], [], ['recvQ' => 100, 'sendQ' => 100], 'process state is unavailable'],
            'uninterruptible I/O state' => [[44], [44 => ['pid' => 44, 'stat' => 'Dl', 'wchan' => 'io_schedule']], ['recvQ' => 100, 'sendQ' => 100], 'uninterruptible I/O state'],
            'missing queue' => [[44], $aliveStates, null, 'queue=unavailable'],
            'open queue' => [[44], $aliveStates, ['recvQ' => 1, 'sendQ' => 100], 'recvQ=1 sendQ=100'],
        ] as $label => $case) {
            $this->assertScgiDecisionExtendsGrace($case[0], $case[1], $case[2], $case[3], $label);
        }
    }

    public function testScgiDecisionObservesSaturatedQueueUntilThresholdThenRestarts(): void
    {
        $states = [44 => ['pid' => 44, 'stat' => 'Sl', 'wchan' => 'poll_schedule_timeout']];
        $queue = ['recvQ' => 100, 'sendQ' => 100];

        // Freeze both the decision sequence and its persisted counter bytes.
        foreach (['observe_wedge', 'observe_wedge', 'restart'] as $index => $action) {
            $decision = \rtorrentProcessScgiUnresponsiveDecision([44], $states, $queue, $this->wedgeStatePath(), 3);
            $this->assertSame($action, $decision['action']);
            $this->assertStringContainsString($index === 2 ? 'consecutive checks' : 'count='.($index + 1).'/3', $decision['message']);
            $this->assertSame((string) ($index + 1), (string) file_get_contents($this->wedgeStatePath()));
        }
    }

    public function testChangedConfigReportPublishesAndClearsStateFile(): void
    {
        $path = $this->tempDir.'/changedConfigs';

        \pmssCheckRtorrentPublishChangedConfigReport(['alice -> bob'], $path, false);
        $this->assertSame('alice -> bob', (string) file_get_contents($path));

        \pmssCheckRtorrentPublishChangedConfigReport([], $path, false);
        $this->assertFalse(file_exists($path));
    }

    public function testChangedConfigReportRejectsUnsafeReportPath(): void
    {
        $path = $this->tempDir.'/nested/../changedConfigs';

        $output = $this->pmssCaptureStdout(static function () use ($path): void {
            \pmssCheckRtorrentPublishChangedConfigReport(['alice -> bob'], $path, false);
        })[1];

        $this->assertStringContainsString('WARN: unsafe changed config report path:', $output);
        $this->assertFalse(file_exists($this->tempDir.'/changedConfigs'));
    }
}
