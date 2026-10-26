<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentThrottleWatchdog.php';

/** Root skips account subprocesses only when both no-follow entries are absent. */
final class QbittorrentThrottlePresenceTest extends TestCase
{
    public function testAbsentPolicyAndRecordSkipReadersAndAccountWorkForEitherDaemonState(): void
    {
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-cap-absent-').'/alice';
        $unreachable = static function (): void { throw new \RuntimeException('Absent cap spawned account work'); };
        foreach ([false, true] as $running) {
            $this->assertSame($running, \pmssQbittorrentThrottleWatchdog('alice', $home, $running,
                ['read' => $unreachable, 'account' => $unreachable, 'stop' => $unreachable]));
        }
        $this->assertFalse(file_exists($home));
    }

    public function testEitherExistingEntryStillReachesAccountComparison(): void
    {
        foreach (['.torrentThrottle', '.config/pmss/torrentClients/qbittorrent.throttleApplied'] as $entry) {
            $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-cap-present-').'/alice';
            $this->pmssWriteFile($home.'/'.$entry, "500\n");
            $calls = 0;
            $this->assertTrue(\pmssQbittorrentThrottleWatchdog('alice', $home, true, [
                'read' => static function (): ?int { return null; },
                'account' => static function () use (&$calls): bool { $calls++; return false; },
            ]));
            $this->assertSame(1, $calls);
        }
    }

    public function testRemovedPolicyUsesTheAbsentFastPathOnTheNextCycle(): void
    {
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-cap-removed-').'/alice';
        $policy = $this->pmssWriteFile($home.'/.torrentThrottle', '0');
        $calls = 0;
        $ops = ['account' => static function () use (&$calls): bool { $calls++; return false; }];
        $this->assertTrue(\pmssQbittorrentThrottleWatchdog('alice', $home, true, $ops));
        $this->assertSame(1, $calls);
        unlink($policy);
        $this->assertTrue(\pmssQbittorrentThrottleWatchdog('alice', $home, true, $ops));
        $this->assertSame(1, $calls);
    }

    public function testDanglingLinksAreNotMistakenForAbsentEntriesOrReadThrough(): void
    {
        foreach (['.torrentThrottle', '.config/pmss/torrentClients/qbittorrent.throttleApplied'] as $entry) {
            $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-cap-link-').'/alice';
            $this->pmssEnsureDir(dirname($home.'/'.$entry));
            $this->pmssCreateSymlinkOrSkip($home.'/missing', $home.'/'.$entry);
            $calls = 0;
            $this->assertTrue(\pmssQbittorrentThrottleWatchdog('alice', $home, true, [
                'account' => static function ($user, $home, $cap) use (&$calls): bool {
                    if ($cap !== null) throw new \RuntimeException('Policy link was read');
                    $calls++; return false;
                },
            ]));
            $this->assertSame(1, $calls);
            $this->assertTrue(is_link($home.'/'.$entry));
        }
    }

    public function testFifoPolicyIsRefusedWithoutBlocking(): void
    {
        if (!function_exists('posix_mkfifo')) throw new SkipTest('FIFO fixtures need POSIX');
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-cap-fifo-').'/alice';
        $this->pmssEnsureDir($home);
        $this->assertTrue(posix_mkfifo($home.'/.torrentThrottle', 0600));
        $calls = 0;
        $started = microtime(true);
        $this->assertTrue(\pmssQbittorrentThrottleWatchdog('alice', $home, true, [
            'account' => static function ($user, $home, $cap) use (&$calls): bool {
                if ($cap !== null) throw new \RuntimeException('FIFO supplied a cap');
                $calls++; return false;
            },
        ]));
        $this->assertSame(1, $calls);
        $this->assertTrue(microtime(true) - $started < 0.5);
    }
}
