<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/systemdControlChannel.php';

/** Hermetic control-channel probe and recovery sequencing tests. */
class SystemdControlChannelTest extends TestCase
{
    /** Capture a fixed probe sequence without touching the host manager. */
    private function captureSequence(array $results, array &$commands): callable
    {
        return static function (string $command, int $timeout) use (&$results, &$commands): array {
            $commands[] = [$command, $timeout];
            return array_shift($results);
        };
    }

    public function testHealthyChannelDoesNotRestartServices(): void
    {
        $commands = []; $steps = []; $logs = [];
        $capture = $this->captureSequence([
            ['rc' => 0, 'stdout' => "Version=252\n"],
            ['rc' => 0, 'stdout' => ''],
        ], $commands);
        $step = static function ($description, $command) use (&$steps): int { $steps[] = $command; return 0; };
        $log = static function ($message) use (&$logs): void { $logs[] = $message; };

        $this->assertSame(0, \pmssSystemdControlChannelCheck($capture, $step, $log));
        $this->assertSame([['systemctl show --property=Version', 15], ['loginctl list-sessions', 15]], $commands);
        $this->assertSame([], $steps);
        $this->assertSame([], $logs);
    }

    public function testVersionProbeRejectsEmptyAndFailedResults(): void
    {
        foreach ([
            ['rc' => 0, 'stdout' => 'Version='],
            ['rc' => 0, 'stdout' => "Version= \n"],
            ['rc' => 1, 'stdout' => "Version=252\n"],
        ] as $result) {
            $commands = [];
            $this->assertSame('systemctl_version', \pmssSystemdControlChannelFailure(
                $this->captureSequence([$result], $commands)
            ));
            $this->assertSame([['systemctl show --property=Version', 15]], $commands);
        }
    }

    public function testLogindProbeFailureIsDetected(): void
    {
        $commands = [];
        $capture = $this->captureSequence([
            ['rc' => 0, 'stdout' => "Version=247\n"],
            ['rc' => 1, 'stdout' => ''],
        ], $commands);
        $this->assertSame('loginctl_sessions', \pmssSystemdControlChannelFailure($capture));
        $this->assertSame(['loginctl list-sessions', 15], $commands[1]);
    }

    public function testRecoveryUsesDependencyOrderAndReprobes(): void
    {
        $commands = []; $steps = []; $logs = [];
        $capture = $this->captureSequence([
            ['rc' => 0, 'stdout' => "Version=252\n"], ['rc' => 1, 'stdout' => ''],
            ['rc' => 0, 'stdout' => "Version=252\n"], ['rc' => 0, 'stdout' => ''],
        ], $commands);
        $step = static function ($description, $command) use (&$steps): int { $steps[] = $command; return 0; };
        $log = static function ($message) use (&$logs): void { $logs[] = $message; };

        $this->assertSame(0, \pmssSystemdControlChannelCheck($capture, $step, $log));
        $this->assertSame([
            'timeout --kill-after=5s 45s systemctl daemon-reexec',
            'timeout --kill-after=5s 45s systemctl restart dbus',
            'timeout --kill-after=5s 45s systemctl restart systemd-logind',
        ], $steps);
        $this->assertSame('control channel recovered', $logs[1]);
        $this->assertSame(4, count($commands));
    }

    public function testRecoveryStopsAtFailedCommand(): void
    {
        foreach ([0, 1, 2] as $failedAt) {
            $commands = []; $steps = []; $logs = [];
            $capture = $this->captureSequence([['rc' => 1, 'stdout' => '']], $commands);
            $step = static function ($description, $command) use (&$steps, $failedAt): int {
                $steps[] = $command;
                return count($steps) === $failedAt + 1 ? 1 : 0;
            };
            $log = static function ($message) use (&$logs): void { $logs[] = $message; };

            $this->assertSame(1, \pmssSystemdControlChannelCheck($capture, $step, $log));
            $this->assertSame($failedAt + 1, count($steps));
            $this->assertSame(1, count($commands));
            $this->assertSame(2, count($logs));
        }
    }

    public function testFailedReprobeReturnsFailure(): void
    {
        $commands = []; $logs = [];
        $capture = $this->captureSequence([
            ['rc' => 1, 'stdout' => ''],
            ['rc' => 0, 'stdout' => "Version=252\n"], ['rc' => 1, 'stdout' => ''],
        ], $commands);
        $step = static function ($description, $command): int { return 0; };
        $log = static function ($message) use (&$logs): void { $logs[] = $message; };

        $this->assertSame(1, \pmssSystemdControlChannelCheck($capture, $step, $log));
        $this->assertSame('control channel still failed: loginctl_sessions', $logs[1]);
    }
}
