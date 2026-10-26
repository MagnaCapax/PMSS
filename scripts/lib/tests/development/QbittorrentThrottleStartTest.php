<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClients.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsInc.php';

/** Apps and Configure converge the same stopped cap before any daemon can save over it. */
final class QbittorrentThrottleStartTest extends TestCase
{
    public function testAppsConfigureAndResolverApplyTheCapBeforeLaunching(): void
    {
        foreach (['apps', 'configure', 'resolver'] as $entry) {
            $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-start-cap-').'/alice';
            // ConfigureStart is called under the switch lock, whose parent already exists.
            $this->pmssEnsureDir($home.'/.config/pmss/torrentClients', 0700);
            $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf',
                "[BitTorrent]\nSession\\GlobalUPSpeedLimit=0\n");
            $events = [];
            $launch = function () use ($path, &$events): bool {
                $this->assertSame('500', \pmssQbittorrentFrontendConfigValues(file_get_contents($path))['BitTorrent']['Session\\GlobalUPSpeedLimit']);
                $events[] = 'launch'; return true;
            };
            $ops = ['build' => '5.2.4', 'system' => true,
                'accountPids' => static function (): array { return []; },
                'throttleRead' => static function (): int { return 500; },
                'write' => static function ($path, $value) use (&$events): bool {
                    $events[] = 'write'; return file_put_contents($path, $value) === strlen($value);
                },
                'pids' => static function (): array { return [101]; },
                'listeners' => static function (): array { return []; },
                'read' => static function (): string { return '23456'; },
                'attempts' => 1,
                'runningVersion' => static function (): string { return 'system'; },
                'launch' => $launch,
            ] + $this->pmssMakeEventCallbacks($events, ['restore' => true]);
            if ($entry === 'apps') $ok = \pmssCustomerTorrentClientToggleStart('qbittorrent', $launch, $home, null, $ops);
            elseif ($entry === 'configure') $ok = \pmssConfigureTorrentClientStart($home, 'system', $ops);
            else $ok = \pmssTorrentClientResolverLaunch($home, 'qbittorrent', false, $ops);
            $this->assertTrue($ok);
            $this->assertSame(['restore', 'write', 'launch'], $events);
            $this->assertFalse(\pmssQbittorrentFrontendThrottleAccount($home, 500, false, $ops));
        }
    }

    public function testFailedThrottleWriteRefusesTheStartAndKeepsTheDetailedNotice(): void
    {
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-start-cap-fail-').'/alice';
        $this->pmssEnsureDir($home.'/.config/pmss/torrentClients', 0700);
        $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', "[BitTorrent]\nSession\\GlobalUPSpeedLimit=0\n");
        $notice = '';
        $this->assertFalse(\pmssQbittorrentFrontendStart($home,
            static function (): void { throw new \RuntimeException('Launch bypassed throttle refusal'); }, [
                'build' => '5.2.4', 'accountPids' => static function (): array { return []; },
                'restore' => static function (): bool { return true; },
                'throttleRead' => static function (): int { return 500; },
                'write' => static function (): bool { return false; },
                'notice' => static function ($home, $message) use (&$notice): void { $notice = $message; },
            ]));
        $this->assertStringContainsString('stopped configuration could not be written', $notice);
        $this->assertSame("500\n", file_get_contents($home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied'));
        $this->assertSame("[BitTorrent]\nSession\\GlobalUPSpeedLimit=0\n",
            file_get_contents($home.'/.config/qBittorrent/qBittorrent.conf'));
    }
}
