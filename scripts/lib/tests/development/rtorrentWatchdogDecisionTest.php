<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/rtorrent/process.php';

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

    public function testGraceStateUsesBaseGraceWithoutRestartMarker(): void
    {
        $state = \rtorrentProcessUnresponsiveGraceState($this->tempDir.'/missing', 120, 1000);

        $this->assertEquals(['grace' => 120, 'restartAge' => 0], $state);
    }

    public function testGraceStateExtendsAfterRecentRestartMarkers(): void
    {
        $marker = $this->tempDir.'/restart-marker';

        file_put_contents($marker, '900');
        $this->assertEquals(['grace' => 600, 'restartAge' => 100], \rtorrentProcessUnresponsiveGraceState($marker, 120, 1000));

        file_put_contents($marker, '1000');
        $this->assertEquals(['grace' => 1200, 'restartAge' => 8000], \rtorrentProcessUnresponsiveGraceState($marker, 120, 9000));

        file_put_contents($marker, '1');
        $this->assertEquals(['grace' => 120, 'restartAge' => 20000], \rtorrentProcessUnresponsiveGraceState($marker, 120, 20001));
    }

    public function testScgiDecisionExtendsGraceWhenProcessStateUnavailable(): void
    {
        $decision = \rtorrentProcessScgiUnresponsiveDecision([44], [], ['recvQ' => 100, 'sendQ' => 100], $this->wedgeStatePath(), 3);

        $this->assertSame('extend_grace', $decision['action']);
        $this->assertStringContainsString('process state is unavailable', $decision['message']);
        $this->assertFalse(file_exists($this->wedgeStatePath()), 'Unavailable process state must not advance queue wedge count');
    }

    public function testScgiDecisionExtendsGraceForUninterruptibleIo(): void
    {
        $decision = \rtorrentProcessScgiUnresponsiveDecision(
            [44],
            [44 => ['pid' => 44, 'stat' => 'Dl', 'wchan' => 'io_schedule']],
            ['recvQ' => 100, 'sendQ' => 100],
            $this->wedgeStatePath(),
            3
        );

        $this->assertSame('extend_grace', $decision['action']);
        $this->assertStringContainsString('uninterruptible I/O state', $decision['message']);
        $this->assertFalse(file_exists($this->wedgeStatePath()), 'D-state must not advance queue wedge count');
    }

    public function testScgiDecisionExtendsGraceWhenQueueIsUnavailableOrOpen(): void
    {
        $states = [44 => ['pid' => 44, 'stat' => 'Sl', 'wchan' => 'poll_schedule_timeout']];

        $unavailable = \rtorrentProcessScgiUnresponsiveDecision([44], $states, null, $this->wedgeStatePath(), 3);
        $this->assertSame('extend_grace', $unavailable['action']);
        $this->assertStringContainsString('queue=unavailable', $unavailable['message']);

        $open = \rtorrentProcessScgiUnresponsiveDecision([44], $states, ['recvQ' => 1, 'sendQ' => 100], $this->wedgeStatePath(), 3);
        $this->assertSame('extend_grace', $open['action']);
        $this->assertStringContainsString('recvQ=1 sendQ=100', $open['message']);
        $this->assertFalse(file_exists($this->wedgeStatePath()), 'Unsaturated queue must not advance wedge count');
    }

    public function testScgiDecisionObservesSaturatedQueueUntilThresholdThenRestarts(): void
    {
        $states = [44 => ['pid' => 44, 'stat' => 'Sl', 'wchan' => 'poll_schedule_timeout']];
        $queue = ['recvQ' => 100, 'sendQ' => 100];

        $first = \rtorrentProcessScgiUnresponsiveDecision([44], $states, $queue, $this->wedgeStatePath(), 3);
        $second = \rtorrentProcessScgiUnresponsiveDecision([44], $states, $queue, $this->wedgeStatePath(), 3);
        $third = \rtorrentProcessScgiUnresponsiveDecision([44], $states, $queue, $this->wedgeStatePath(), 3);

        $this->assertSame('observe_wedge', $first['action']);
        $this->assertStringContainsString('count=1/3', $first['message']);
        $this->assertSame('observe_wedge', $second['action']);
        $this->assertStringContainsString('count=2/3', $second['message']);
        $this->assertSame('restart', $third['action']);
        $this->assertStringContainsString('consecutive checks', $third['message']);
    }
}
