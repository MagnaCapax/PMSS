<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentThrottleAccount.php';

/** Hermetic account locks, pending saves and same-UID process observations. */
final class QbittorrentThrottleAccountTest extends TestCase
{
    private $home;
    private $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-throttle-account-').'/alice';
        $this->path = $this->pmssWriteFile($this->home.'/.config/qBittorrent/qBittorrent.conf',
            "[BitTorrent]\nSession\\GlobalUPSpeedLimit=0\n");
    }

    private function ops(array $extra = []): array
    {
        return $extra + ['build' => '5.2.4', 'stopAttempts' => 0,
            'recordWrite' => static function ($path, $content): bool { return file_put_contents($path, $content) === strlen($content); },
            'write' => static function ($path, $content): bool { return file_put_contents($path, $content) === strlen($content); },
        ] + self::pmssMakeCallbacks(['accountPids' => [], 'restore' => true, 'notice' => null]);
    }

    public function testLiveComparisonDoesNotRestoreOrWrite(): void
    {
        $unreachable = static function (): void { throw new \RuntimeException('Live comparison wrote the configuration'); };
        $ops = $this->ops([
            'restore' => $unreachable, 'write' => $unreachable,
        ] + self::pmssMakeCallbacks(['accountPids' => [101]]));
        $this->assertTrue(\pmssQbittorrentThrottleAccount($this->home, 512, false, $ops));
        $this->assertFalse(\pmssQbittorrentThrottleAccount($this->home, 0, false, $ops));
    }

    public function testAnyRunningSameUidDaemonRefusesWriteAndRestore(): void
    {
        $ops = $this->ops([
            'restore' => static function (): void { throw new \RuntimeException('Running process allowed restore'); },
        ] + self::pmssMakeCallbacks(['accountPids' => [101]]));
        $this->assertSame(null, \pmssQbittorrentThrottleAccount($this->home, 512, true, $ops));
        $this->assertStringContainsString('GlobalUPSpeedLimit=0', file_get_contents($this->path));
    }

    public function testShutdownSaveCompletesBeforeRestoreAndThrottleWrite(): void
    {
        $live = true;
        $events = [];
        $ops = $this->ops(['stopAttempts' => 2,
            'accountPids' => static function () use (&$live): array { return $live ? [101] : []; },
            'pause' => function () use (&$live, &$events): void {
                $events[] = 'exit-save';
                file_put_contents($this->path, "[BitTorrent]\nSession\\GlobalUPSpeedLimit=900\nOther=preserved\n");
                $live = false;
            }] + self::pmssMakeCallbacks(['restore' => true], $events));
        $this->assertTrue(\pmssQbittorrentThrottleAccount($this->home, 512, true, $ops));
        $this->assertSame(['exit-save', 'restore'], $events);
        $this->assertStringContainsAllStrings(['GlobalUPSpeedLimit=512', 'Other=preserved'], file_get_contents($this->path));
    }

    public function testPendingSaveIsPromotedBeforeThrottleIsApplied(): void
    {
        $this->pmssWriteFile(dirname($this->path).'/qBittorrent_new.conf', "[BitTorrent]\nSession\\GlobalUPSpeedLimit=900\nLatest=kept\n");
        $ops = $this->ops(['restore' => 'pmssQbittorrentRestore'] + self::pmssMakeCallbacks(['stopped' => true]));
        $this->assertTrue(\pmssQbittorrentThrottleAccount($this->home, 256, true, $ops));
        $this->assertStringContainsAllStrings(['GlobalUPSpeedLimit=256', 'Latest=kept'], file_get_contents($this->path));
        $this->assertFalse(file_exists(dirname($this->path).'/qBittorrent_new.conf'));
    }

    public function testRestoreAndWriteFailuresDoNotReportSuccess(): void
    {
        foreach (['restore', 'write'] as $operation) {
            $this->assertSame(null, \pmssQbittorrentThrottleAccount($this->home, 512, true,
                $this->ops(self::pmssMakeCallbacks([$operation => false]))));
        }
    }

    public function testSwitchLockRefusesThrottleComparisonAndWrite(): void
    {
        $lockPath = $this->pmssWriteFile($this->home.'/.config/pmss/torrentClients/qbittorrent.lock', '');
        $lock = fopen($lockPath, 'c');
        flock($lock, LOCK_EX);
        try {
            foreach ([false, true] as $apply)
                $this->assertSame(null, \pmssQbittorrentThrottleAccount($this->home, 512, $apply, $this->ops()));
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
