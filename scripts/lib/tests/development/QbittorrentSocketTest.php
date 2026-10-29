<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentStart.php';

/** Socket observations use fake proc data and injected process operations only. */
final class QbittorrentSocketTest extends TestCase
{
    public function testOnlyAnUnexpectedDefaultWebUiPortIsUnsafe(): void
    {
        foreach ([['0100007F', 23456, 'ready'], ['00000000', 23456, 'ready'],
            [str_repeat('0', 32), 23456, 'ready'], ['0100007F', 8080, 'unsafe'],
            ['00000000', 8080, 'unsafe'], ['0100007F', 12345, 'ready'],
            ['0100000A', 23456, 'ready'], ['00000000', 50000, 'waiting'],
            ['00000000000000000000000001000000', 23456, 'ready']] as list($address, $port, $expected)) {
            $this->assertSame($expected, \pmssQbittorrentFrontendSocketStatus(
                [['address' => $address, 'port' => $port]], 23456, 12345));
        }
        $this->assertSame('unsafe', \pmssQbittorrentFrontendSocketStatus([
            ['address' => '0100007F', 'port' => 23456], ['address' => '00000000', 'port' => 8080],
        ], 23456));
        foreach ([[8080, 23456, 50000], [23456, 8080, 50000], [23456, 34567, 8080]] as list($account, $web, $peer))
            $this->assertSame($account === 8080 || $web === 8080 ? 'ready' : 'waiting',
                \pmssQbittorrentFrontendSocketStatus([['address' => '00000000', 'port' => 8080]], $account, $web, $peer));
    }

    public function testListenerReaderMatchesOnlyTheDaemonSocketInodes(): void
    {
        $proc = $this->pmssMakeTempDir('pmss-qbt-sockets-');
        $this->pmssEnsureDir($proc.'/101/fd');
        $this->pmssCreateSymlinkOrSkip('socket:[123]', $proc.'/101/fd/3');
        $this->pmssWriteFile($proc.'/net/tcp', "header\n0: 0100007F:5BA0 00000000:0000 0A 0 0 0 1000 0 123\n"
            ."1: 00000000:1F90 00000000:0000 0A 0 0 0 1001 0 999\n");
        $this->pmssWriteFile($proc.'/net/tcp6', "header\n");
        $this->assertSame([['address' => '0100007F', 'port' => 23456]], \pmssQbittorrentFrontendListeners([101], $proc));
        $this->assertSame(null, \pmssQbittorrentFrontendListeners([102], $proc));
    }

    public function testUnsafeListenerStopsAndLeavesANoticeSafeListenerDoesNeither(): void
    {
        foreach ([['0100007F', 23456, true], ['00000000', 23456, true],
            ['0100007F', 8080, false], [str_repeat('0', 32), 23456, true]] as list($address, $port, $ok)) {
            $events = [];
            $result = \pmssQbittorrentFrontendSocketWait('/fixture/t', [
                'read' => static function ($path): string { return substr($path, -16) === '.qbittorrentPort' ? '23456' : ''; },
                'pause' => static function (): void { throw new \RuntimeException('Immediate decision slept'); },
            ]
                + self::pmssMakeCallbacks(['pids' => [101], 'listeners' => [['address' => $address, 'port' => $port]]])
                + self::pmssMakeCallbacks(['stop' => true, 'notice' => null, 'refuse' => true], $events));
            $this->assertSame($ok, $result);
            $this->assertSame($ok ? [] : ['stop', 'refuse', 'notice'], $events);
        }
    }

    public function testMissingOrUnreadableListenerLeavesTheDaemonRunningWithoutNotice(): void
    {
        foreach ([[], null] as $sockets) {
            $events = [];
            $this->assertTrue(\pmssQbittorrentFrontendSocketWait('/fixture/t', ['attempts' => 2]
                + self::pmssMakeCallbacks(['read' => '23456', 'pids' => [101], 'pause' => null, 'listeners' => $sockets])
                + self::pmssMakeCallbacks(['stop' => true, 'notice' => null, 'refuse' => true], $events)));
            $this->assertSame([], $events);
        }
    }

    public function testSlowStartupIsObservedSafelyOnALaterWatchdogCycle(): void
    {
        $sockets = [];
        $stop = static function (): bool { throw new \RuntimeException('Slow startup was stopped'); };
        $ops = ['attempts' => 2,
            'listeners' => static function () use (&$sockets): array { return $sockets; }, 'stop' => $stop,
            'notice' => $stop, 'refuse' => $stop,
        ] + self::pmssMakeCallbacks(['read' => '23456', 'pids' => [101], 'pause' => null]);
        $this->assertTrue(\pmssQbittorrentFrontendSocketWait('/fixture/t', $ops));
        $sockets = [['address' => '0100007F', 'port' => 23456]];
        $this->assertTrue(\pmssQbittorrentFrontendSocketWait('/fixture/t', $ops));
    }

    public function testLateUnsafeListenerIsStoppedOnTheNextWatchdogObservation(): void
    {
        $events = [];
        $sockets = [];
        $ops = ['attempts' => 1,
            'listeners' => static function () use (&$sockets): array { return $sockets; },
        ] + self::pmssMakeCallbacks(['read' => '23456', 'pids' => [101], 'pause' => null])
            + self::pmssMakeCallbacks(['stop' => true, 'notice' => null, 'refuse' => true], $events);
        $this->assertTrue(\pmssQbittorrentFrontendSocketWait('/fixture/t', $ops));
        $sockets = [['address' => '00000000', 'port' => 8080]];
        $this->assertFalse(\pmssQbittorrentFrontendSocketWait('/fixture/t', $ops));
        $this->assertSame(['stop', 'refuse', 'notice'], $events);
    }

    public function testProcessDeathAtEndOfWaitFailsInsteadOfPassingAsSlowStartup(): void
    {
        $reads = 0;
        $this->assertFalse(\pmssQbittorrentFrontendSocketWait('/fixture/t', [
            'pids' => static function () use (&$reads): array { return ++$reads === 1 ? [101] : []; }, 'attempts' => 1,
            'pause' => static function (): void { throw new \RuntimeException('Single observation slept'); },
        ] + self::pmssMakeCallbacks(['read' => '23456', 'listeners' => []])));
    }

    public function testAsynchronousLaunchMayAppearAfterTheFirstPollsButNeverAfterTheGrace(): void
    {
        // The panel launch is backgrounded; the process can be absent on the first checks.
        $polls = 0;
        $ops = [
            'pids' => static function () use (&$polls): array { return ++$polls > 3 ? [101] : []; },
        ] + self::pmssMakeCallbacks(['read' => '23456', 'listeners' => [['address' => '0100007F', 'port' => 23456]], 'pause' => null]);
        $this->assertTrue(\pmssQbittorrentFrontendSocketWait('/fixture/t', $ops));
        $pauses = 0;
        $this->assertFalse(\pmssQbittorrentFrontendSocketWait('/fixture/t', ['appearAttempts' => 5,
            'pause' => static function () use (&$pauses): void { $pauses++; },
        ] + self::pmssMakeCallbacks(['pids' => []]) + $ops));
        $this->assertSame(4, $pauses);
    }

    public function testConfiguredWebUiAndPeer8080AreAllowedForBothSectionLayouts(): void
    {
        foreach (['4.2.5', '5.3.0'] as $build) foreach (['web', 'peer'] as $role) {
            $config = "[Meta]\nMigrationVersion=12\n[Preferences]\nWebUI\\Port=".($role === 'web' ? 8080 : 34567)
                ."\nConnection\\PortRangeMin=".($role === 'peer' ? 8080 : 50000)."\n[WebUI]\nPort="
                .($role === 'web' ? 8080 : 34567)."\n[BitTorrent]\nSession\\Port=".($role === 'peer' ? 8080 : 50000)."\n";
            $this->assertTrue(\pmssQbittorrentFrontendSocketWait('/fixture/t', [
                'build' => $build, 'attempts' => 1,
                'read' => static function ($path) use ($config): string { return basename($path) === '.qbittorrentPort' ? '23456' : $config; },
                'stop' => static function (): bool { throw new \RuntimeException('Customer port was stopped'); },
                'pause' => static function (): void { throw new \RuntimeException('Observation slept'); },
            ] + self::pmssMakeCallbacks(['pids' => [101], 'listeners' => [['address' => '00000000', 'port' => 8080]]])));
        }
    }
}
