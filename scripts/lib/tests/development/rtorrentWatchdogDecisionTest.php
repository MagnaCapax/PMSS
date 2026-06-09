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
        foreach ($state as $path) {
            file_put_contents($path, '1');
        }

        \rtorrentProcessClearResolvedWatchdogState($state, true, false);
        $this->assertFalse(file_exists($state['missing']), 'missing marker clears once rtorrent is present');
        $this->assertTrue(file_exists($state['acceptQueueWedge']), 'queue wedge marker remains while rtorrent is present');
        foreach (['startMarker', 'startFailure', 'sessionReset', 'escalation'] as $key) {
            $this->assertFalse(file_exists($state[$key]), $key.' clears once start path has resolved');
        }

        foreach ($state as $path) {
            file_put_contents($path, '1');
        }
        \rtorrentProcessClearResolvedWatchdogState($state, false, false);
        $this->assertFalse(file_exists($state['missing']), 'missing marker clears when no executor remains');
        $this->assertFalse(file_exists($state['acceptQueueWedge']), 'queue wedge marker clears when rtorrent is gone');
        foreach (['startMarker', 'startFailure', 'sessionReset', 'escalation'] as $key) {
            $this->assertTrue(file_exists($state[$key]), $key.' remains until a process or executor appears');
        }
    }

    public function testGraceStateUsesBaseGraceWithoutRestartMarker(): void
    {
        $state = \rtorrentProcessUnresponsiveGraceState($this->tempDir.'/missing', 120, 1000);

        $this->assertEquals(['grace' => 120, 'restartAge' => 0], $state);
    }

    public function testGraceStateExtendsAfterRecentRestartMarkers(): void
    {
        $marker = $this->tempDir.'/restart-marker';

        foreach ([
            ['900', 1000, 600, 100],
            ['1000', 9000, 1200, 8000],
            ['1', 20001, 120, 20000],
        ] as $case) {
            file_put_contents($marker, $case[0]);
            $this->assertEquals(['grace' => $case[2], 'restartAge' => $case[3]], \rtorrentProcessUnresponsiveGraceState($marker, 120, $case[1]));
        }
    }

    public function testScgiDecisionExtendsGraceForUnavailableOrUnsafeProcessStates(): void
    {
        foreach ([
            'missing process state' => [[], 'process state is unavailable'],
            'uninterruptible I/O state' => [[44 => ['pid' => 44, 'stat' => 'Dl', 'wchan' => 'io_schedule']], 'uninterruptible I/O state'],
        ] as $label => $case) {
            $decision = \rtorrentProcessScgiUnresponsiveDecision([44], $case[0], ['recvQ' => 100, 'sendQ' => 100], $this->wedgeStatePath(), 3);

            $this->assertSame('extend_grace', $decision['action'], $label);
            $this->assertStringContainsString($case[1], $decision['message'], $label);
            $this->assertFalse(file_exists($this->wedgeStatePath()), $label.' must not advance queue wedge count');
        }
    }

    public function testScgiDecisionExtendsGraceWhenQueueIsUnavailableOrOpen(): void
    {
        $states = [44 => ['pid' => 44, 'stat' => 'Sl', 'wchan' => 'poll_schedule_timeout']];

        foreach ([[null, 'queue=unavailable'], [['recvQ' => 1, 'sendQ' => 100], 'recvQ=1 sendQ=100']] as $case) {
            $decision = \rtorrentProcessScgiUnresponsiveDecision([44], $states, $case[0], $this->wedgeStatePath(), 3);

            $this->assertSame('extend_grace', $decision['action']);
            $this->assertStringContainsString($case[1], $decision['message']);
            $this->assertFalse(file_exists($this->wedgeStatePath()), 'Unsaturated queue must not advance wedge count');
        }
    }

    public function testScgiDecisionObservesSaturatedQueueUntilThresholdThenRestarts(): void
    {
        $states = [44 => ['pid' => 44, 'stat' => 'Sl', 'wchan' => 'poll_schedule_timeout']];
        $queue = ['recvQ' => 100, 'sendQ' => 100];

        $first = \rtorrentProcessScgiUnresponsiveDecision([44], $states, $queue, $this->wedgeStatePath(), 3);
        $second = \rtorrentProcessScgiUnresponsiveDecision([44], $states, $queue, $this->wedgeStatePath(), 3);
        $third = \rtorrentProcessScgiUnresponsiveDecision([44], $states, $queue, $this->wedgeStatePath(), 3);

        foreach ([
            [$first, 'observe_wedge', 'count=1/3'],
            [$second, 'observe_wedge', 'count=2/3'],
            [$third, 'restart', 'consecutive checks'],
        ] as $case) {
            $this->assertSame($case[1], $case[0]['action']);
            $this->assertStringContainsString($case[2], $case[0]['message']);
        }
    }
}
