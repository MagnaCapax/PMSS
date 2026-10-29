<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentWatchdog.php';

/** Missing-port repair preserves strict reconciliation and stopped account writes. */
final class QbittorrentPortRepairTest extends TestCase
{
    private $home;
    private $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-port-repair-').'/alice';
        $this->path = $this->pmssWriteFile($this->home.'/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nLocale=en\n");
        $this->pmssWriteFile($this->home.'/.qbittorrentPort', "45678\n");
    }

    private function ops(array $extra = []): array
    {
        return $extra + ['build' => '5.2.4'] + self::pmssMakeCallbacks(['accountPids' => [], 'restore' => true]);
    }

    public function testRepairFeedsTheUnchangedStrictReconcilerAndIsIdempotent(): void
    {
        $this->assertFalse(\pmssQbittorrentPortEnsure('alice', $this->home));
        chmod($this->path, 0600);
        $this->assertTrue(\pmssQbittorrentAccountWatchdog($this->home, false, $this->ops()));
        $updated = file_get_contents($this->path);
        $this->assertStringContainsAllStrings(["Locale=en\n", "WebUI\\Port=45678\n", 'WebUI\\Address=127.0.0.1'], $updated);
        $this->assertSame(0600, fileperms($this->path) & 0777);
        $this->assertTrue(\pmssQbittorrentPortEnsure('alice', $this->home));
        $this->assertTrue(\pmssQbittorrentAccountWatchdog($this->home, false, $this->ops()));
        $this->assertSame($updated, file_get_contents($this->path));
    }

    public function testRepairUsesTheAuthoritativeBuildSectionAndPreservesCredentials(): void
    {
        foreach (['5.3.0', '4.3.9'] as $build) {
            $config = "[Meta]\nMigrationVersion=12\n[Preferences]\nLocale=en\nWebUI\\Password_PBKDF2=hash\n[WebUI]\nPassword_PBKDF2=hash\n";
            $this->pmssWriteFile($this->path, $config);
            $this->assertTrue(\pmssQbittorrentPortRepairMissing('alice', $this->home, $this->ops(['build' => $build])));
            $updated = file_get_contents($this->path);
            $this->assertSame('45678', \pmssQbittorrentWebUiValue($updated, 'Port', $build));
            $this->assertSame('127.0.0.1', \pmssQbittorrentWebUiValue($updated, 'Address', $build));
            $this->assertSame('hash', \pmssQbittorrentWebUiValue($updated, 'Password_PBKDF2', $build));
            $this->assertStringContainsString("MigrationVersion=12\n", $updated);
        }
    }

    public function testPresentPortIncludingCustomerValuesIsNeverRepaired(): void
    {
        foreach (['45678', '12345', 'invalid'] as $port) {
            $config = "[Preferences]\nWebUI\\Port=".$port."\nWebUI\\Address=*\n";
            $this->pmssWriteFile($this->path, $config);
            $this->assertFalse(\pmssQbittorrentPortRepairNeeded('alice', $this->home, '5.2.4'));
            $this->assertTrue(\pmssQbittorrentPortRepairMissing('alice', $this->home, $this->ops()));
            $this->assertSame($config, file_get_contents($this->path));
        }
    }

    public function testInvalidAssignmentsAndEmptyConfigsDoNotRequestRepair(): void
    {
        foreach (['', 'abc', '80', '65536'] as $port) {
            $this->pmssWriteFile($this->home.'/.qbittorrentPort', $port);
            $this->assertFalse(\pmssQbittorrentPortRepairNeeded('alice', $this->home, '5.2.4'));
        }
        $this->pmssWriteFile($this->home.'/.qbittorrentPort', '45678');
        foreach (['', "; incomplete config\n"] as $config) {
            $this->pmssWriteFile($this->path, $config);
            $this->assertFalse(\pmssQbittorrentPortRepairNeeded('alice', $this->home, '5.2.4'));
        }
        unlink($this->path);
        $this->assertFalse(\pmssQbittorrentPortRepairNeeded('alice', $this->home, '5.2.4'));
    }

    public function testUnsafeAccountAndLinkedConfigCannotRequestRepair(): void
    {
        foreach (['otherusr', 'bad-user'] as $user)
            $this->assertFalse(\pmssQbittorrentPortRepairNeeded($user, $this->home, '5.2.4'));
        $target = $this->pmssWriteFile($this->home.'/unmanaged.conf', "[Preferences]\nLocale=en\n");
        unlink($this->path);
        symlink($target, $this->path);
        $this->assertFalse(\pmssQbittorrentPortRepairNeeded('alice', $this->home, '5.2.4'));
        $this->assertSame("[Preferences]\nLocale=en\n", file_get_contents($target));
    }

    public function testLiveAccountAndBusySwitchLockDeferTheWrite(): void
    {
        $before = file_get_contents($this->path);
        $this->assertFalse(\pmssQbittorrentPortRepairMissing('alice', $this->home,
            $this->ops(self::pmssMakeCallbacks(['accountPids' => [101]]))));
        $lockPath = $this->pmssWriteFile($this->home.'/.config/pmss/torrentClients/qbittorrent.lock', '');
        $lock = fopen($lockPath, 'c');
        flock($lock, LOCK_EX);
        try {
            $this->assertFalse(\pmssQbittorrentAccountWatchdog($this->home, false,
                $this->ops(self::pmssMakeCallbacks(['notice' => null]))));
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        $this->assertSame($before, file_get_contents($this->path));
    }

    public function testPendingSaveIsRestoredBeforeMissingPortIsRepaired(): void
    {
        $this->pmssWriteFile(dirname($this->path).'/qBittorrent_new.conf', "[Preferences]\nLatest=kept\n");
        $restoreOps = $this->ops(self::pmssMakeCallbacks(['stopped' => true]));
        $this->assertTrue(\pmssQbittorrentAccountWatchdog($this->home, false, $this->ops([
            'restore' => static function ($home) use ($restoreOps): bool { return \pmssQbittorrentRestore($home, $restoreOps); },
        ])));
        $this->assertStringContainsAllStrings(['Latest=kept', 'WebUI\\Port=45678'], file_get_contents($this->path));
        $this->assertFalse(file_exists(dirname($this->path).'/qBittorrent_new.conf'));
    }

    public function testFailedRestoreDoesNotReachTheRepair(): void
    {
        $this->assertFalse(\pmssQbittorrentAccountWatchdog($this->home, false,
            $this->ops(self::pmssMakeCallbacks(['restore' => false]))));
        $this->assertSame("[Preferences]\nLocale=en\n", file_get_contents($this->path));
    }

    public function testRunningMissingPortStopsBeforeObservationAndDefersWhileLive(): void
    {
        $events = [];
        $this->assertTrue(\pmssQbittorrentWatchdogRunning('alice', $this->home, [
            'portRepairStop' => static function () use (&$events): void { $events[] = 'term'; },
            'account' => static function (): void { throw new \RuntimeException('Live process reached account repair'); },
        ] + self::pmssMakeCallbacks(['running' => true])));
        $this->assertSame(['term'], $events);
        $this->assertSame("[Preferences]\nLocale=en\n", file_get_contents($this->path));
    }

    public function testStoppedAfterTermRepairsThenReconcilesBeforeRestart(): void
    {
        $live = true;
        $events = [];
        $this->assertFalse(\pmssQbittorrentWatchdogRunning('alice', $this->home, [
            'running' => static function () use (&$live): bool { return $live; },
            'portRepairStop' => static function () use (&$live, &$events): void { $events[] = 'term'; $live = false; },
            'account' => function ($user, $home, $observe = false, $release = false) use (&$events): bool {
                $events[] = $release ? 'release' : 'restore';
                return $release || \pmssQbittorrentAccountWatchdog($home, false, $this->ops());
            },
            'port' => static function ($user, $home) use (&$events): bool {
                $events[] = 'port'; return \pmssQbittorrentPortEnsure($user, $home);
            },
            'throttle' => static function ($user, $running): bool { return $running; },
        ] + self::pmssMakeCallbacks(['auth' => false, 'managed' => null])));
        $this->assertSame(['term', 'restore', 'port', 'release'], $events);
        $this->assertStringContainsString('WebUI\\Port=45678', file_get_contents($this->path));
    }
}
