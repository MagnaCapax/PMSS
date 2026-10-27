<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentThrottleWatchdog.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentThrottleStopped.php';

/** Account state distinguishes PMSS caps from customer-owned upload limits. */
final class QbittorrentThrottleOwnershipTest extends TestCase
{
    public function testWatchdogPreservesCustomerLimitsAndRestartsOnlyForAnAppliedChange(): void
    {
        foreach ([[null, 5000, null, 5000, []], [500, 300, null, 300, []],
            [500, 0, null, 500, ['stop', 'write', 'start']],
            [500, 900, null, 500, ['stop', 'write', 'start']],
            [null, 500, 500, null, ['stop', 'write', 'start']],
            [null, 300, 500, 300, []]] as list($cap, $current, $applied, $expected, $expectedEvents)) {
            $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-cap-owned-').'/alice';
            $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf',
                "[BitTorrent]\nSession\\GlobalUPSpeedLimit=".$current."\n");
            $state = $home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied';
            if ($cap !== null) $this->pmssWriteFile($home.'/.torrentThrottle', $cap."\n");
            if ($applied !== null) $this->pmssWriteFile($state, $applied."\n");
            $running = true;
            $events = [];
            $ops = [
                'running' => static function () use (&$running): bool { return $running; },
                'account' => static function ($user, $home, $cap, $apply) use (&$running, &$events): ?bool {
                    return \pmssQbittorrentThrottleAccount($home, $cap, $apply, [
                        'build' => '5.2.4', 'accountPids' => static function () use (&$running): array { return $running ? [101] : []; },
                        'recordWrite' => static function ($path, $content): bool { return file_put_contents($path, $content) === strlen($content); },
                        'write' => static function ($path, $value) use (&$running, &$events): bool {
                            if ($running) throw new \RuntimeException('Daemon-owned settings written while running');
                            $events[] = 'write'; return file_put_contents($path, $value) === strlen($value);
                        },
                    ] + self::pmssMakeCallbacks(['restore' => true]));
                },
                'stop' => static function () use (&$running, &$events): void { $events[] = 'stop'; $running = false; },
            ] + self::pmssMakeCallbacks(['read' => $cap, 'log' => null]);
            if (!\pmssQbittorrentThrottleWatchdog('alice', $home, true, $ops)) {
                $events[] = 'start'; $running = true;
            }
            $this->assertSame($expectedEvents, $events);
            $this->assertSame($expected === null ? null : (string) $expected,
                \pmssQbittorrentConfigValues(file_get_contents($path))['BitTorrent']['Session\\GlobalUPSpeedLimit'] ?? null);
            $this->assertSame($cap !== null && $expectedEvents !== [], file_exists($state));
            $events = [];
            $this->assertTrue(\pmssQbittorrentThrottleWatchdog('alice', $home, true, $ops));
            $this->assertSame([], $events, 'The next watchdog cycle must be a no-op');
        }
    }

    public function testNoCapAndNoRecordNeverReadsOrRestoresDaemonSettingsOnEitherSide(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-no-cap-');
        $unreachable = static function (): void { throw new \RuntimeException('Unthrottled account touched'); };
        foreach (['pmssQbittorrentThrottleAccount', 'pmssQbittorrentFrontendThrottleAccount'] as $account)
            foreach ([false, true] as $apply)
                $this->assertFalse($account($home, null, $apply, ['build' => '5.3.0',
                    'accountPids' => $unreachable, 'restore' => $unreachable, 'write' => $unreachable]));
        $this->assertFalse(file_exists($home.'/.config'));
    }

    public function testBothCopiesKeepStricterLimitsAndOnlyTheRootPathRemovesItsOwnValue(): void
    {
        foreach (['pmssQbittorrentThrottleAccount', 'pmssQbittorrentFrontendThrottleAccount'] as $account)
            foreach ([null, 300, 500, 900] as $current) {
                $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-cap-parity-').'/alice';
                $config = "[BitTorrent]\nSession\\GlobalUPSpeedLimit=".($current ?? 0)."\n";
                $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', $config);
                $ops = ['build' => '5.3.0', 'write' => 'pmssConfigureFileWrite', 'recordWrite' => 'pmssConfigureFileWrite']
                    + self::pmssMakeCallbacks(['accountPids' => [], 'restore' => true]);
                $changed = $current === null || $current > 500;
                $this->assertSame($changed, $account($home, 500, true, $ops));
                $this->assertSame($changed ? '500' : (string) $current,
                    \pmssQbittorrentConfigValues(file_get_contents($path))['BitTorrent']['Session\\GlobalUPSpeedLimit']);
                if ($changed) {
                    $state = $home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied';
                    $this->assertSame("500\n", file_get_contents($state));
                    $this->assertSame(0600, fileperms($state) & 0777);
                    $rootPath = $account === 'pmssQbittorrentThrottleAccount';
                    $this->assertSame($rootPath, $account($home, null, true, $ops));
                    $this->assertSame(!$rootPath, file_exists($state));
                    if (!$rootPath) {
                        $this->assertSame("500\n", file_get_contents($state));
                        $this->assertSame('500', \pmssQbittorrentConfigValues(file_get_contents($path))['BitTorrent']['Session\\GlobalUPSpeedLimit']);
                    }
                }
            }
    }

    public function testDeferredNoticeClearsAfterTheExitSaveAndSuccessfulApply(): void
    {
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-cap-notice-').'/alice';
        $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', "[BitTorrent]\nSession\\GlobalUPSpeedLimit=0\n");
        $notice = $this->pmssWriteFile($home.'/.config/pmss/torrentClients/qbittorrent.notice', 'qBittorrent upload throttle deferred: busy');
        $calls = 0;
        $this->assertTrue(\pmssQbittorrentThrottleAccount($home, 500, true, ['build' => '5.2.4',
            'accountPids' => static function () use (&$calls): array { return ++$calls < 60 ? [101] : []; },
            'write' => 'pmssConfigureFileWrite', 'recordWrite' => 'pmssConfigureFileWrite']
            + self::pmssMakeCallbacks(['pause' => null, 'restore' => true])));
        $this->assertFalse(file_exists($notice));
    }
}
