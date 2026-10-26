<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureSettings.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClients.php';

/** A daemonize exit code alone never establishes a surviving requested build. */
final class QbittorrentSwitchStartTest extends TestCase
{
    public function testStartRefusesAnAlreadyRunningInstanceBeforeRestoreOrLaunch(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-start-running-');
        $this->assertFalse(\pmssConfigureTorrentClientStart($home, 'system', [
            'accountPids' => static function (): array { return [101]; },
            'notice' => static function (): void {},
            'restore' => static function (): void { throw new \RuntimeException('Running config touched'); },
        ]));
    }

    public function testSlowConfigureStartWithoutAListenerIsLeftRunning(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-slow-configure-');
        $this->assertTrue(\pmssConfigureTorrentClientStart($home, 'system', [
            'build' => '5.2.4', 'accountPids' => static function (): array { return []; },
            'restore' => static function (): bool { return true; },
            'pids' => static function (): array { return [101]; },
            'listeners' => static function (): array { return []; },
            'read' => static function (): string { return '23456'; },
            'pause' => static function (): void {}, 'attempts' => 2,
            'ready' => static function (): bool { return false; },
            'runningVersion' => static function (): string { return 'system'; },
            'launch' => static function (): bool { return true; },
            'stop' => static function (): void { throw new \RuntimeException('Slow daemon stopped'); },
            'notice' => static function (): void { throw new \RuntimeException('Slow daemon refusal notice'); },
        ]));
    }

    public function testImmediateExitFailsTheSwitchAndRestartsThePreviousBuild(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-switch-exit-');
        $this->pmssEnsureDir($home.'/bin/qbittorrent');
        $binary = $this->pmssWriteFile($home.'/qbittorrent-nox', 'fixture');
        $starts = [];
        $ops = ['versions' => ['5.2.4' => ['kind' => 'static']],
            'backup' => static function (): array { return ['ok' => true]; },
            'stop' => static function (): bool { return true; },
            'repoint' => static function (): bool { return true; },
            'restore' => static function (): bool { return true; },
            'start' => static function ($home, $version) use (&$starts, $binary): bool {
                $starts[] = $version;
                return \pmssConfigureTorrentClientStart($home, $version, [
                    'resolve' => static function () use ($binary): string { return $binary; },
                    'accountPids' => static function (): array { return []; },
                    'restore' => static function (): bool { return true; },
                    'build' => $version === 'system' ? '4.6.7' : '5.2.4',
                    'launch' => static function (): bool { return true; },
                    'pids' => static function () use ($version): array { return $version === 'system' ? [101] : []; },
                    'runningVersion' => static function () use ($version): string { return $version; },
                    'listeners' => static function (): array { return []; },
                    'read' => static function (): string { return '23456'; }, 'attempts' => 1,
                ]);
            }];
        $result = \pmssConfigureTorrentClientSwitch($home, ['client' => 'qbittorrent', 'version' => '5.2.4'], $ops);
        $this->assertFalse($result['ok']);
        $this->assertSame(['5.2.4', 'system'], $starts);
        $this->assertSame("system\n", file_get_contents($home.'/.config/pmss/torrentClients/qbittorrent.lastRun'));
        $this->assertSame("4.6.7\n", file_get_contents($home.'/.config/pmss/torrentClients/qbittorrent.lastBuild'));
        $this->assertFalse(file_exists($home.'/.config/pmss/torrentClients/qbittorrent.lastgood'));
        $this->assertStringContainsString('previous version was restored', $result['message']);
    }

    public function testLiveWrongBuildCannotBecomeLastgoodEvenWithoutAListener(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-switch-wrong-build-');
        $this->assertFalse(\pmssConfigureTorrentClientStart($home, 'system', [
            'accountPids' => static function (): array { return []; },
            'restore' => static function (): bool { return true; },
            'launch' => static function (): bool { return true; },
            'pids' => static function (): array { return [101]; },
            'runningVersion' => static function (): string { return '4.3.9-lt1.2'; },
            'listeners' => static function (): array { return []; },
            'read' => static function (): string { return '23456'; }, 'attempts' => 1,
        ]));
        $this->assertFalse(file_exists($home.'/.config/pmss/torrentClients/qbittorrent.lastBuild'));
    }
}
