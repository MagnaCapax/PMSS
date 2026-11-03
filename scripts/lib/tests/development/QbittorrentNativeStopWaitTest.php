<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsInc.php';

/** Stop observations and signals are injected, including the post-KILL exit delay. */
final class QbittorrentNativeStopWaitTest extends TestCase
{
    public function testStopWaitsForExitAfterBothGracefulAndForcedSignals(): void
    {
        foreach ([0, 1, 2, 29, 30, 31, 32, 79, 80, 81, 100] as $exitAfter) {
            $pauses = 0; $signals = [];
            \pmssCustomerNativeStop([], [
                'signal' => static function ($binaries, $signal) use (&$signals): int { $signals[] = $signal; return 1; },
                'pids' => static function () use (&$pauses, $exitAfter): array { return $pauses < $exitAfter ? [101] : []; },
                'pause' => static function () use (&$pauses): void { $pauses++; },
            ]);
            $this->assertSame($exitAfter < 30 ? [15] : [15, 9], $signals);
            $this->assertSame(min($exitAfter, 80), $pauses);
        }
    }

    public function testSharedWaitPreservesArgumentsStrictEmptyResultsAndProbeBudget(): void
    {
        foreach ([
            [[], 3, true, 1, 0], [[101], 3, false, 3, 3],
            [null, 2, false, 2, 2], [false, 2, false, 2, 2],
            [[], 0, false, 0, 0], [[], -1, false, 0, 0],
        ] as [$result, $budget, $expected, $probes, $sleeps]) {
            $calls = []; $pauses = 0;
            $pids = static function (...$args) use (&$calls, $result) {
                $calls[] = $args;
                return $result;
            };
            $pause = static function () use (&$pauses): void { $pauses++; };
            $this->assertSame($expected, \pmssCustomerNativeWaitForExit($pids, ['/fixture/t', 'deluge'], $budget, $pause));
            $this->assertSame(array_fill(0, $probes, ['/fixture/t', 'deluge']), $calls);
            $this->assertSame($sleeps, $pauses);
        }
    }

    public function testSharedWaitPropagatesProbeAndPauseFailuresWithoutRetry(): void
    {
        foreach (['probe', 'pause'] as $boundary) {
            $error = new \RuntimeException($boundary); $events = [];
            $pids = static function () use (&$events, $error, $boundary): array {
                $events[] = 'probe';
                if ($boundary === 'probe') throw $error;
                return [101];
            };
            $pause = static function () use (&$events, $error): void { $events[] = 'pause'; throw $error; };
            try {
                \pmssCustomerNativeWaitForExit($pids, [], 3, $pause);
                $this->fail('Expected the injected '.$boundary.' failure');
            } catch (\RuntimeException $actual) {
                $this->assertSame($error, $actual);
            }
            $this->assertSame($boundary === 'probe' ? ['probe'] : ['probe', 'pause'], $events);
        }
    }
}
