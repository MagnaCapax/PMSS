<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentThrottleWatchdog.php';

/** Root skips account subprocesses only when no cap is stored and no applied record exists. */
final class QbittorrentThrottlePresenceTest extends TestCase
{
    public function testNoStoredCapAndNoRecordSkipAccountWorkForEitherDaemonState(): void
    {
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-cap-absent-').'/fixtur01';
        $unreachable = static function (): void { throw new \RuntimeException('Absent cap spawned account work'); };
        foreach ([false, true] as $running) {
            $this->assertSame($running, \pmssQbittorrentThrottleWatchdog('fixtur01', $home, $running,
                ['account' => $unreachable, 'stop' => $unreachable] + self::pmssMakeCallbacks(['read' => null])));
        }
        $this->assertFalse(file_exists($home));
    }

    public function testStoredCapOrAppliedRecordReachesAccountComparison(): void
    {
        foreach ([[500, false], [null, true]] as [$cap, $record]) {
            $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-cap-present-').'/fixtur01';
            if ($record) $this->pmssWriteFile($home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied', "500\n");
            $calls = 0;
            $this->assertTrue(\pmssQbittorrentThrottleWatchdog('fixtur01', $home, true, [
                'account' => static function ($user, $home, $seen) use (&$calls, $cap): bool { $calls++; return $seen !== $cap; },
            ] + self::pmssMakeCallbacks(['read' => $cap])));
            $this->assertSame(1, $calls);
        }
    }

    public function testHomeCopyOfTheCapIsNotTheDecision(): void
    {
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-cap-home-').'/fixtur01';
        $this->pmssWriteFile($home.'/.torrentThrottle', "500\n");
        $unreachable = static function (): void { throw new \RuntimeException('Home copy spawned account work'); };
        $this->assertTrue(\pmssQbittorrentThrottleWatchdog('fixtur01', $home, true,
            ['account' => $unreachable, 'stop' => $unreachable] + self::pmssMakeCallbacks(['read' => null])));
    }

    public function testDanglingRecordLinkStillReachesComparisonWithoutReadingThrough(): void
    {
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-cap-link-').'/fixtur01';
        $entry = $home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied';
        $this->pmssEnsureDir(dirname($entry));
        $this->pmssCreateSymlinkOrSkip($home.'/missing', $entry);
        $calls = 0;
        $this->assertTrue(\pmssQbittorrentThrottleWatchdog('fixtur01', $home, true, [
            'account' => static function ($user, $home, $cap) use (&$calls): bool {
                if ($cap !== null) throw new \RuntimeException('Record link was read');
                $calls++; return false;
            },
        ] + self::pmssMakeCallbacks(['read' => null])));
        $this->assertSame(1, $calls);
        $this->assertTrue(is_link($entry));
    }
}
