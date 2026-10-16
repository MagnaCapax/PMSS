<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentWebUiAuth.php';

final class QbittorrentWebUiAuthReviewTest extends TestCase
{
    /** Supply a suite-owned marker and replace process and config operations. */
    private function fixture(array &$events): array
    {
        $root = $this->pmssMakeTempDir('pmss-qbt-review-');
        $native = $this->pmssWriteFile($root.'/qbittorrent-nox', 'binary');
        return [
            'enabled' => static function (): bool { return true; },
            'contextSafe' => static function (): bool { return true; },
            'pathSafe' => static function (): bool { return true; },
            'read' => static function (): string { return "[Preferences]\nWebUI\\LocalHostAuth=false\n"; },
            'write' => static function () use (&$events): bool { $events[] = 'write'; return true; },
            'pids' => static function (): array { return [123]; },
            'roots' => [$native],
            'exe' => static function () use ($native): string { return $native; },
            'mntNs' => static function (): string { return 'mnt:[1]'; },
            'signal' => static function () use (&$events): bool { $events[] = 'signal'; return true; },
            'log' => static function ($user, $message) use (&$events): void { $events[] = 'log:'.$message; },
            'stateDir' => $root.'/run',
            'now' => 100000,
        ];
    }

    /** A replaced on-disk binary keeps its native proc link name. */
    public function testDeletedSuffixStillMatchesNativeRoot(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        $native = $ops['roots'][0];
        $ops['exe'] = static function () use ($native): string { return $native.' (deleted)'; };
        $this->assertSame([123], \pmssQbittorrentWebUiAuthNativePids('alice', $ops));
        $this->assertTrue(\pmssQbittorrentWebUiAuthPidIsNative(123, $ops));
    }

    /** Matching paths in another mount context stay outside the native set. */
    public function testDifferentMountContextExcludesMatchingPath(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        $ops['mntNs'] = static function (int $pid): string { return $pid === 0 ? 'mnt:[1]' : 'mnt:[2]'; };
        $this->assertSame([], \pmssQbittorrentWebUiAuthNativePids('alice', $ops));
    }

    /** Unreadable, empty, and unrelated proc links do not match. */
    public function testIncompleteProcessDetailsDoNotMatch(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        foreach ([false, '', '/other/qbittorrent-nox'] as $exe) {
            $ops['exe'] = static function () use ($exe) { return $exe; };
            $this->assertFalse(\pmssQbittorrentWebUiAuthPidIsNative(123, $ops));
        }
        $ops['mntNs'] = static function () { return false; };
        $this->assertFalse(\pmssQbittorrentWebUiAuthPidIsNative(123, $ops));
    }

    /** A failed signal leaves the interval open for the next pass. */
    public function testFailedSignalCanRetryImmediately(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        $ops['signal'] = static function () use (&$events): bool { $events[] = 'signal'; return false; };
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $ops));
        $this->assertFalse(\pmssQbittorrentWebUiAuthStateActive('alice', $ops));
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $ops));
        $this->assertSame(2, count(array_filter($events, static function ($event): bool { return $event === 'signal'; })));
        $this->assertSame(1, count(array_filter($events, static function ($event): bool { return strpos($event, 'log:') === 0; })));
    }

    /** A changed process identity is skipped at the final check. */
    public function testChangedProcessBeforeSignalIsSkipped(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        $native = $ops['roots'][0];
        $reads = 0;
        $ops['exe'] = static function () use ($native, &$reads): string { return ++$reads === 1 ? $native : '/other/binary'; };
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $ops));
        $this->assertSame(0, count(array_filter($events, static function ($event): bool { return $event === 'signal'; })));
        $this->assertFalse(\pmssQbittorrentWebUiAuthStateActive('alice', $ops));
    }

    /** Empty and malformed marker timestamps do not hold an active interval. */
    public function testMalformedMarkerIsInactive(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        $dir = $ops['stateDir'];
        $this->pmssEnsureDir($dir);
        $path = $dir.'/qbittorrentWebui-alice.state';
        foreach (['', "oops\n0\n", "oops\n1\n", "0\n0\n"] as $value) {
            $this->pmssWriteFile($path, $value);
            $this->assertFalse(\pmssQbittorrentWebUiAuthStateActive('alice', $ops));
        }
    }

    /** Repeated deferred passes emit only the first transition. */
    public function testDeferredStatusLogsOnce(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        $this->assertTrue(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $ops));
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $ops));
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $ops));
        $logs = array_values(array_filter($events, static function ($event): bool { return strpos($event, 'log:') === 0; }));
        $this->assertSame(2, count($logs));
        $ops['now'] = 186401;
        $this->assertTrue(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $ops));
        $logs = array_values(array_filter($events, static function ($event): bool { return strpos($event, 'log:') === 0; }));
        $this->assertSame(3, count($logs));
    }

    /** The marker records one report per distinct non-stop status. */
    public function testStateStatusTransitionIsRetained(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        $this->pmssEnsureDir($ops['stateDir']);
        $this->pmssWriteFile($ops['stateDir'].'/qbittorrentWebui-alice.state', "0\n0\n");
        $this->assertTrue(\pmssQbittorrentWebUiAuthStateShouldLog('alice', 'state-failed', $ops));
        $this->assertFalse(\pmssQbittorrentWebUiAuthStateShouldLog('alice', 'state-failed', $ops));
        $this->assertTrue(\pmssQbittorrentWebUiAuthStateShouldLog('alice', 'deferred', $ops));
    }
}
