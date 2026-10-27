<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClients.php';
require_once __DIR__.'/../../user/qbittorrentThrottleStopped.php';

/** Exercise full production wait budgets with virtual time and fixture-only process operations. */
final class QbittorrentWaitTest extends TestCase
{
    public function testConfigureStopUsesItsFullBudgetWithoutSleepingOrSignallingARealPid(): void
    {
        $pauses = 0; $signals = [];
        $this->assertFalse(\pmssConfigureTorrentClientStop('/fixture/t', [
            'signal' => static function ($pid, $signal) use (&$signals): bool { $signals[] = [$pid, $signal]; return true; },
            'pause' => static function () use (&$pauses): void { $pauses++; },
        ] + self::pmssMakeCallbacks(['pids' => [101]])));
        $this->assertSame([[101, 15]], $signals);
        $this->assertSame(600, $pauses);
    }

    public function testUnsafeStopWaitsForExitAfterTheForceSignalWithoutSleeping(): void
    {
        $pauses = 0; $killed = false; $signals = [];
        $this->assertTrue(\pmssQbittorrentFrontendSocketStop('/fixture/t', [
            'pids' => static function () use (&$pauses, &$killed): array { return $killed && $pauses >= 620 ? [] : [101]; },
            'signal' => static function ($pid, $signal) use (&$killed, &$signals): bool {
                $signals[] = $signal; $killed = $signal === 9; return true;
            },
            'pause' => static function () use (&$pauses): void { $pauses++; },
        ]));
        $this->assertSame([15, 9], $signals);
        $this->assertSame(620, $pauses);
    }

    public function testConfigureStabilityUsesTheInjectedClockWithinTheSocketWait(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-virtual-start-');
        $now = 0.0; $pauses = 0;
        $this->assertTrue(\pmssConfigureTorrentClientStart($home, 'system', [
            'build' => '5.2.4',
            'clock' => static function () use (&$now): float { return $now; },
            'pause' => static function () use (&$now, &$pauses): void { $now += 0.5; $pauses++; },
        ] + self::pmssMakeCallbacks(['accountPids' => [], 'restore' => true, 'throttle' => true, 'launch' => true,
            'pids' => [101], 'listeners' => [['address' => '0100007F', 'port' => 23456]],
            'runningVersion' => 'system', 'read' => '23456'])));
        $this->assertSame(6, $pauses);
        $this->assertSame(3.0, $now);
    }

    public function testSlowStartExhaustsTheProductionObservationBudgetWithoutStopping(): void
    {
        $pauses = 0;
        $this->assertTrue(\pmssQbittorrentFrontendSocketWait('/fixture/t', [
            'build' => '5.2.4',
            'pause' => static function () use (&$pauses): void { $pauses++; },
            'stop' => static function (): void { throw new \RuntimeException('Healthy slow start stopped'); },
            'notice' => static function (): void { throw new \RuntimeException('Inconclusive observation reported'); },
        ] + self::pmssMakeCallbacks(['read' => '23456', 'pids' => [101], 'listeners' => []])));
        $this->assertSame(199, $pauses);
    }

    public function testBothThrottleWritersExhaustTheShutdownBudgetWithoutRealPauses(): void
    {
        foreach (['pmssQbittorrentThrottleAccount', 'pmssQbittorrentFrontendThrottleAccount'] as $apply) {
            $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-virtual-throttle-').'/alice';
            $pauses = 0; $notice = '';
            $this->assertSame(null, $apply($home, 500, true, [
                'pause' => static function () use (&$pauses): void { $pauses++; },
                'notice' => static function ($home, $text) use (&$notice): void { $notice = $text; },
                'restore' => static function (): void { throw new \RuntimeException('Still-running config restored'); },
            ] + self::pmssMakeCallbacks(['accountPids' => [101]])));
            $this->assertSame(600, $pauses);
            $this->assertStringContainsString('still running', $notice);
        }
    }
}
