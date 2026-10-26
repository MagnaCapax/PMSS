<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsInc.php';

/** Stop observations and signals are injected, including the post-KILL exit delay. */
final class QbittorrentNativeStopWaitTest extends TestCase
{
    public function testStopWaitsForExitAfterBothGracefulAndForcedSignals(): void
    {
        foreach ([2, 32, 100] as $exitAfter) {
            $pauses = 0; $signals = [];
            \pmssCustomerNativeStop([], [
                'signal' => static function ($binaries, $signal) use (&$signals): int { $signals[] = $signal; return 1; },
                'pids' => static function () use (&$pauses, $exitAfter): array { return $pauses < $exitAfter ? [101] : []; },
                'pause' => static function () use (&$pauses): void { $pauses++; },
            ]);
            $this->assertSame($exitAfter <= 30 ? [15] : [15, 9], $signals);
            $this->assertSame(min($exitAfter, 80), $pauses);
        }
    }
}
