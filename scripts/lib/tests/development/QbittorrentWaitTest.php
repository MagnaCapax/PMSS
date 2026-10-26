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
            'pids' => static function (): array { return [101]; },
            'signal' => static function ($pid, $signal) use (&$signals): bool { $signals[] = [$pid, $signal]; return true; },
            'pause' => static function () use (&$pauses): void { $pauses++; },
        ]));
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
            'build' => '5.2.4', 'accountPids' => static function (): array { return []; },
            'restore' => static function (): bool { return true; },
            'throttle' => static function (): bool { return true; },
            'launch' => static function (): bool { return true; },
            'pids' => static function (): array { return [101]; },
            'listeners' => static function (): array { return [['address' => '0100007F', 'port' => 23456]]; },
            'runningVersion' => static function (): string { return 'system'; },
            'read' => static function (): string { return '23456'; },
            'clock' => static function () use (&$now): float { return $now; },
            'pause' => static function () use (&$now, &$pauses): void { $now += 0.5; $pauses++; },
        ]));
        $this->assertSame(6, $pauses);
        $this->assertSame(3.0, $now);
    }

    public function testSlowStartExhaustsTheProductionObservationBudgetWithoutStopping(): void
    {
        $pauses = 0;
        $this->assertTrue(\pmssQbittorrentFrontendSocketWait('/fixture/t', [
            'build' => '5.2.4', 'read' => static function (): string { return '23456'; },
            'pids' => static function (): array { return [101]; },
            'listeners' => static function (): array { return []; },
            'pause' => static function () use (&$pauses): void { $pauses++; },
            'stop' => static function (): void { throw new \RuntimeException('Healthy slow start stopped'); },
            'notice' => static function (): void { throw new \RuntimeException('Inconclusive observation reported'); },
        ]));
        $this->assertSame(199, $pauses);
    }

    public function testBothThrottleWritersExhaustTheShutdownBudgetWithoutRealPauses(): void
    {
        foreach (['pmssQbittorrentThrottleAccount', 'pmssQbittorrentFrontendThrottleAccount'] as $apply) {
            $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-virtual-throttle-').'/alice';
            $pauses = 0; $notice = '';
            $this->assertSame(null, $apply($home, 500, true, [
                'accountPids' => static function (): array { return [101]; },
                'pause' => static function () use (&$pauses): void { $pauses++; },
                'notice' => static function ($home, $text) use (&$notice): void { $notice = $text; },
                'restore' => static function (): void { throw new \RuntimeException('Still-running config restored'); },
            ]));
            $this->assertSame(600, $pauses);
            $this->assertStringContainsString('still running', $notice);
        }
    }
}
