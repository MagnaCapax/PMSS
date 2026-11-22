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
            'roots' => [$native],
            'log' => static function ($user, $message) use (&$events): void { $events[] = 'log:'.$message; },
            'stateDir' => $root.'/run',
            'now' => 100000,
        ] + self::pmssMakeCallbacks([
            'exe' => $native, 'contextSafe' => true, 'pathSafe' => true,
            'read' => "[Preferences]\nWebUI\\LocalHostAuth=false\n", 'pids' => [123], 'mntNs' => 'mnt:[1]'
        ]) + self::pmssMakeCallbacks(['write' => true, 'signal' => true], $events);
    }

    /** A replaced on-disk binary keeps its native proc link name. */
    public function testDeletedSuffixStillMatchesNativeRoot(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        $native = $ops['roots'][0];
        $ops = self::pmssMakeCallbacks(['exe' => $native.' (deleted)']) + $ops;
        $this->assertSame([123], \pmssQbittorrentWebUiAuthNativePids('fixtur01', $ops));
        $this->assertTrue(\pmssQbittorrentWebUiAuthPidIsNative(123, $ops));
    }

    /** Matching paths in another mount context stay outside the native set. */
    public function testDifferentMountContextExcludesMatchingPath(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        $ops['mntNs'] = static function (int $pid): string { return $pid === 0 ? 'mnt:[1]' : 'mnt:[2]'; };
        $this->assertSame([], \pmssQbittorrentWebUiAuthNativePids('fixtur01', $ops));
    }

    /** Unreadable, empty, and unrelated proc links do not match. */
    public function testIncompleteProcessDetailsDoNotMatch(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        foreach ([false, '', '/other/qbittorrent-nox'] as $exe) {
            $ops = self::pmssMakeCallbacks(['exe' => $exe]) + $ops;
            $this->assertFalse(\pmssQbittorrentWebUiAuthPidIsNative(123, $ops));
        }
        $ops = self::pmssMakeCallbacks(['mntNs' => false]) + $ops;
        $this->assertFalse(\pmssQbittorrentWebUiAuthPidIsNative(123, $ops));
    }

    /** A failed signal leaves the interval open for the next pass. */
    public function testFailedSignalCanRetryImmediately(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        $ops = self::pmssMakeCallbacks(['signal' => false], $events) + $ops;
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('fixtur01', '/tmp/pmss-qbt-test/fixtur01', $ops));
        $this->assertFalse(\pmssQbittorrentWebUiAuthStateActive('fixtur01', $ops));
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('fixtur01', '/tmp/pmss-qbt-test/fixtur01', $ops));
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
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('fixtur01', '/tmp/pmss-qbt-test/fixtur01', $ops));
        $this->assertSame(0, count(array_filter($events, static function ($event): bool { return $event === 'signal'; })));
        $this->assertFalse(\pmssQbittorrentWebUiAuthStateActive('fixtur01', $ops));
    }

    /** Empty and malformed marker timestamps do not hold an active interval. */
    public function testMalformedMarkerIsInactive(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        $dir = $ops['stateDir'];
        $this->pmssEnsureDir($dir);
        $path = $dir.'/qbittorrentWebui-fixtur01.state';
        foreach (['', "oops\n0\n", "oops\n1\n", "0\n0\n"] as $value) {
            $this->pmssWriteFile($path, $value);
            $this->assertFalse(\pmssQbittorrentWebUiAuthStateActive('fixtur01', $ops));
        }
    }

    /** Repeated deferred passes emit only the first transition. */
    public function testDeferredStatusLogsOnce(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        $this->assertTrue(\pmssQbittorrentWebUiAuthEnsure('fixtur01', '/tmp/pmss-qbt-test/fixtur01', $ops));
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('fixtur01', '/tmp/pmss-qbt-test/fixtur01', $ops));
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('fixtur01', '/tmp/pmss-qbt-test/fixtur01', $ops));
        $logs = array_values(array_filter($events, static function ($event): bool { return strpos($event, 'log:') === 0; }));
        $this->assertSame(2, count($logs));
        $ops['now'] = 186401;
        $this->assertTrue(\pmssQbittorrentWebUiAuthEnsure('fixtur01', '/tmp/pmss-qbt-test/fixtur01', $ops));
        $logs = array_values(array_filter($events, static function ($event): bool { return strpos($event, 'log:') === 0; }));
        $this->assertSame(3, count($logs));
    }

    /** The marker records one report per distinct non-stop status. */
    public function testStateStatusTransitionIsRetained(): void
    {
        $events = [];
        $ops = $this->fixture($events);
        $this->pmssEnsureDir($ops['stateDir']);
        $stateFile = $this->pmssWriteFile($ops['stateDir'].'/qbittorrentWebui-fixtur01.state', "0\n0\n");
        $this->assertTrue(chmod($stateFile, 0600));
        $this->assertTrue(\pmssQbittorrentWebUiAuthStateShouldLog('fixtur01', 'state-failed', $ops));
        $this->assertFalse(\pmssQbittorrentWebUiAuthStateShouldLog('fixtur01', 'state-failed', $ops));
        $this->assertTrue(\pmssQbittorrentWebUiAuthStateShouldLog('fixtur01', 'deferred', $ops));
    }
}
