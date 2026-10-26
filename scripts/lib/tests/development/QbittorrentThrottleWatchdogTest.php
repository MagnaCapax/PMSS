<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentThrottleWatchdog.php';

/** A fake daemon saves on exit exactly where the real client used to erase the live write. */
final class QbittorrentThrottleWatchdogTest extends TestCase
{
    private $home;
    private $path;
    private $running;
    private $events;
    private $throttle;
    private $build;

    protected function setUp(): void
    {
        parent::setUp();
        $root = $this->pmssMakeTrackedHomeRoot('pmss-qbt-throttle-cycle-');
        $this->home = $root.'/alice';
        $this->path = $this->pmssWriteFile($this->home.'/.config/qBittorrent/qBittorrent.conf',
            "[Meta]\nMigrationVersion=8\n[BitTorrent]\nSession\\GlobalUPSpeedLimit=512\n");
        $this->running = true;
        $this->events = [];
        $this->throttle = 512;
        $this->build = '5.2.4-lt1.2';
        $this->pmssWriteFile($this->home.'/.torrentThrottle', "512\n");
    }

    /** Exercise the real read/transform/write path and consume its existing launch decision. */
    private function cycle(): bool
    {
        $running = \pmssQbittorrentThrottleWatchdog('alice', $this->home, $this->running, [
            'read' => function (): ?int { return $this->throttle; },
            'running' => function (): bool { return $this->running; },
            'account' => function ($user, $home, $throttle, $apply): ?bool {
                return \pmssQbittorrentThrottleAccount($home, $throttle, $apply, [
                    'build' => $this->build,
                    'accountPids' => function (): array { return $this->running ? [101] : []; },
                    'restore' => static function (): bool { return true; },
                    'recordWrite' => static function ($path, $content): bool { return file_put_contents($path, $content) === strlen($content); },
                    'write' => function ($path, $content): bool {
                        $this->assertFalse($this->running, 'A live daemon owned the config at write time');
                        $this->events[] = 'write';
                        return file_put_contents($path, $content) === strlen($content);
                    },
                ]);
            },
            'stop' => function (): void {
                $this->events[] = 'stop';
                // The old implementation's pre-stop legacy write is lost here.
                $values = \pmssQbittorrentConfigValues(file_get_contents($this->path));
                $value = $values['BitTorrent']['Session\\GlobalUPSpeedLimit'] ?? '0';
                file_put_contents($this->path, "[Meta]\nMigrationVersion=8\n[BitTorrent]\nSession\\GlobalUPSpeedLimit=".$value."\n");
                $this->running = false;
            },
            'log' => static function (): void {},
        ]);
        // The service watchdog starts only when its convergence callback returned false.
        if (!$running) { $this->events[] = 'start'; $this->running = true; }
        return $running;
    }

    public function testRunningUnchangedThrottleDoesNotWriteOrRestart(): void
    {
        $this->assertTrue($this->cycle());
        $this->assertSame([], $this->events);
    }

    public function testChangedThrottleStopsWritesAndStartsExactlyOnceThenSavedConfigIsANoop(): void
    {
        $this->throttle = 256;
        $this->assertFalse($this->cycle());
        $this->assertSame(['stop', 'write', 'start'], $this->events);
        $this->assertSame('256', \pmssQbittorrentConfigValues(file_get_contents($this->path))['BitTorrent']['Session\\GlobalUPSpeedLimit']);
        $this->events = [];
        $this->assertTrue($this->cycle());
        $this->assertSame([], $this->events);
    }

    public function testStoppedDaemonGetsTheThrottleBeforeStart(): void
    {
        $this->running = false;
        $this->throttle = 256;
        $this->assertFalse($this->cycle());
        $this->assertSame(['write', 'start'], $this->events);
    }

    public function testRemovingARunningThrottleNeedsOnlyOneRestart(): void
    {
        $this->pmssWriteFile($this->home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied', "512\n");
        $this->throttle = 0;
        $this->assertFalse($this->cycle());
        $this->assertSame(['stop', 'write', 'start'], $this->events);
        $this->assertStringNotContainsString('GlobalUP', file_get_contents($this->path));
        $this->events = [];
        $this->assertTrue($this->cycle());
        $this->assertSame([], $this->events);
    }

    public function testRemovalDeletesBothKeysAndUnlimitedExitSaveDoesNotRestartAgain(): void
    {
        $this->pmssWriteFile($this->home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied', "512\n");
        $this->running = false;
        file_put_contents($this->path, "[BitTorrent]\nSession\\GlobalUPSpeedLimit=512\n[Preferences]\nConnection\\GlobalUPLimit=512\n");
        $this->throttle = 0;
        $this->cycle();
        $this->assertStringNotContainsString('GlobalUP', file_get_contents($this->path));
        file_put_contents($this->path, "[BitTorrent]\nSession\\GlobalUPSpeedLimit=0\n");
        $this->events = [];
        $this->assertTrue($this->cycle());
        $this->assertSame([], $this->events);
    }

    public function testOldSelectionWritesTheOldKey(): void
    {
        $this->running = false;
        $this->build = '4.3.9-lt1.2';
        $this->throttle = 123;
        $this->cycle();
        $values = \pmssQbittorrentConfigValues(file_get_contents($this->path));
        $this->assertSame('123', $values['Preferences']['Connection\\GlobalUPLimit']);
        $this->events = [];
        $this->assertTrue($this->cycle());
        $this->assertSame([], $this->events);
    }

    public function testFailedStoppedApplyBlocksStartAndUnchangedDaemonIsLeftRunning(): void
    {
        foreach ([false, true] as $running) {
            $this->assertTrue(\pmssQbittorrentThrottleWatchdog('alice', $this->home, $running, [
                'read' => static function (): int { return 256; },
                'account' => static function (): ?bool { return null; },
                'stop' => static function (): void { throw new \RuntimeException('Failed comparison stopped a daemon'); },
            ]));
        }
    }
}
