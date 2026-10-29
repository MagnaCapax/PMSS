<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentWebUiAuth.php';

final class QbittorrentWebUiAuthStateTest extends TestCase
{
    /** Provide a native fixture and replace every external side effect. */
    private function fixture(array &$events, string $config = "[Preferences]\nWebUI\\LocalHostAuth=false\n"): array
    {
        $root = $this->pmssMakeTempDir('pmss-qbt-state-');
        // All process and file collaborators are replaced with fixture callbacks.
        $native = $this->pmssWriteFile($root.'/usr/bin/qbittorrent-nox', 'binary');
        $pids = [123];
        $now = 100000;
        $opts = [
            'read' => static function () use (&$config): string { return $config; },
            'currentConfig' => static function () use (&$config): string { return $config; },
            'setConfig' => static function (string $value) use (&$config): void { $config = $value; },
            'write' => static function () use (&$config, &$events): bool {
                $events[] = 'write';
                $config = \pmssQbittorrentWebUiAuthForce($config);
                return true;
            },
            'pids' => static function () use (&$pids): array { return $pids; },
            'roots' => [$native],
            'signal' => static function (int $pid) use (&$events): bool { $events[] = 'signal:'.$pid; return true; },
            'log' => static function (string $user, string $message) use (&$events): void { $events[] = 'log:'.$message; },
            // Marker locking still depends on a suite-owned temporary directory.
            'stateDir' => $root.'/run',
            // A fixed clock makes the daily interval deterministic.
            'now' => $now,
        ] + self::pmssMakeCallbacks(['enabled' => true, 'contextSafe' => true, 'pathSafe' => true,
            'exe' => $native, 'mntNs' => 'mnt:[1]']);
        return $opts;
    }

    /** A successful stop leaves a later unchanged pass quiet. */
    public function testWrittenConfigStopsOnceAndThenConverges(): void
    {
        $events = [];
        $opts = $this->fixture($events);
        $this->assertTrue(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $this->assertStringContainsString('WebUI\\Password_PBKDF2='.\pmssQbittorrentWebUiAuthSentinel(), $opts['currentConfig']());
        $this->assertSame(1, count(array_filter($events, static function ($event): bool { return $event === 'write'; })));
        $this->assertSame(1, count(array_filter($events, static function ($event): bool { return strpos($event, 'signal:') === 0; })));
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $this->assertSame(1, count(array_filter($events, static function ($event): bool { return $event === 'write'; })));
        $this->assertSame(3, count($events));
        $opts['now'] = 186401;
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $this->assertSame(3, count($events));
    }

    /** A new difference cannot trigger another immediate stop. */
    public function testRecentStopDefersUntilDayElapsed(): void
    {
        $events = [];
        $opts = $this->fixture($events);
        $this->assertTrue(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $opts['setConfig']("[Preferences]\nWebUI\\LocalHostAuth=false\n");
        $opts['now'] = 100100;
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $opts['now'] = 186401;
        $this->assertTrue(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $this->assertSame(2, count(array_filter($events, static function ($event): bool { return strpos($event, 'signal:') === 0; })));
    }

    /** A stopped pass lets the written file become the next start's input. */
    public function testStoppedPassClearsBudgetAfterApply(): void
    {
        $events = [];
        $opts = $this->fixture($events);
        $this->assertTrue(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $opts = self::pmssMakeCallbacks(['pids' => []]) + $opts;
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $opts = self::pmssMakeCallbacks(['pids' => [123], 'read' => "[Preferences]\nWebUI\\LocalHostAuth=false\n"]) + $opts;
        $this->assertTrue(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
    }

    /** After a clean later pass, a new difference may be reconciled. */
    public function testVerifiedLaterPassAllowsAChangedConfig(): void
    {
        $events = [];
        $opts = $this->fixture($events);
        $this->assertTrue(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $opts['setConfig']("[Preferences]\nWebUI\\LocalHostAuth=false\n");
        $opts['now'] = 100100;
        $this->assertTrue(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
    }

    /** Failed writes leave process control untouched. */
    public function testWriteFailureDoesNotSignalAndLogsOnce(): void
    {
        $events = [];
        $opts = $this->fixture($events);
        $opts = self::pmssMakeCallbacks(['write' => false]) + $opts;
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $this->assertSame(1, count($events));
        $this->assertStringContainsString('write failed', $events[0]);
    }

    /** An already-settled config needs no write or process action. */
    public function testUnchangedConfigDoesNotWriteOrStop(): void
    {
        $events = [];
        $opts = $this->fixture($events);
        $opts['read'] = static function (): string { return \pmssQbittorrentWebUiAuthForce("[Preferences]\n"); };
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $this->assertSame([], $events);
    }

    /** A process in another mount context is ignored even with a native path. */
    public function testOtherMountContextAndUnreadableExecutableAreIgnored(): void
    {
        $events = [];
        $opts = $this->fixture($events);
        $opts['mntNs'] = static function (int $pid): string { return $pid === 0 ? 'mnt:[1]' : 'mnt:[2]'; };
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $this->assertSame(['write'], $events);
        $opts = self::pmssMakeCallbacks(['mntNs' => 'mnt:[1]', 'exe' => false]) + $opts;
        $this->assertSame([], \pmssQbittorrentWebUiAuthNativePids('alice', $opts));
    }

    /** A later duplicate cannot force a second write or stop. */
    public function testDuplicateKeysConvergeWithoutAnotherStop(): void
    {
        $events = [];
        $config = "[Preferences]\nWebUI\\LocalHostAuth=false\nWebUI\\LocalHostAuth=false\n[Other]\nx=1\n[Preferences]\nWebUI\\AuthSubnetWhitelistEnabled=true\n";
        $opts = $this->fixture($events, $config);
        $this->assertTrue(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $this->assertSame(1, count(array_filter($events, static function ($event): bool { return strpos($event, 'signal:') === 0; })));
        $this->assertSame($opts['currentConfig'](), \pmssQbittorrentWebUiAuthForce($opts['currentConfig']()));
    }

    /** Missing root state storage must fail before the file write. */
    public function testUnavailableStateDoesNotWriteOrSignal(): void
    {
        $events = [];
        $opts = $this->fixture($events);
        $opts['stateDir'] = $this->pmssMakeTempFile('pmss-qbt-block-').'/child';
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', $opts));
        $this->assertSame(1, count($events));
        $this->assertStringContainsString('state unavailable', $events[0]);
    }
}
