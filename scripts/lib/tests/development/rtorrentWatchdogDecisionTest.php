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
