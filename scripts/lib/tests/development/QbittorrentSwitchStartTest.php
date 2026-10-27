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
            'restore' => static function (): void { throw new \RuntimeException('Running config touched'); },
        ] + self::pmssMakeCallbacks(['accountPids' => [101], 'notice' => null])));
    }

    public function testSlowConfigureStartWithoutAListenerIsLeftRunning(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-slow-configure-');
        $this->assertTrue(\pmssConfigureTorrentClientStart($home, 'system', [
            'build' => '5.2.4', 'attempts' => 2,
            'stop' => static function (): void { throw new \RuntimeException('Slow daemon stopped'); },
            'notice' => static function (): void { throw new \RuntimeException('Slow daemon refusal notice'); },
        ] + self::pmssMakeCallbacks(['accountPids' => [], 'restore' => true, 'pids' => [101], 'listeners' => [],
            'read' => '23456', 'pause' => null, 'ready' => false, 'runningVersion' => 'system', 'launch' => true])));
    }

    public function testImmediateExitFailsTheSwitchAndRestartsThePreviousBuild(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-switch-exit-');
        $this->pmssEnsureDir($home.'/bin/qbittorrent');
        $binary = $this->pmssWriteFile($home.'/qbittorrent-nox', 'fixture');
        $starts = [];
        $ops = ['versions' => ['5.2.4' => ['kind' => 'static']],
            'start' => static function ($home, $version) use (&$starts, $binary): bool {
                $starts[] = $version;
                return \pmssConfigureTorrentClientStart($home, $version, [
                    'build' => $version === 'system' ? '4.6.7' : '5.2.4',
                    'attempts' => 1,
                ] + self::pmssMakeCallbacks(['resolve' => $binary, 'accountPids' => [], 'restore' => true,
                    'launch' => true, 'pids' => $version === 'system' ? [101] : [], 'runningVersion' => $version,
                    'listeners' => [], 'read' => '23456']));
            }] + self::pmssMakeCallbacks(['backup' => ['ok' => true], 'stop' => true, 'repoint' => true, 'restore' => true]);
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
        $this->assertFalse(\pmssConfigureTorrentClientStart($home, 'system', ['attempts' => 1]
            + self::pmssMakeCallbacks(['accountPids' => [], 'restore' => true, 'launch' => true, 'pids' => [101],
                'runningVersion' => '4.3.9-lt1.2', 'listeners' => [], 'read' => '23456'])));
        $this->assertFalse(file_exists($home.'/.config/pmss/torrentClients/qbittorrent.lastBuild'));
    }
}
