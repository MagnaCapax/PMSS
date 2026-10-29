<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TemporaryHomeRootTestCase.php';
require_once dirname(__DIR__, 2).'/user/qbittorrent.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentStoppedConfig.php';

/** A Configure switch restarts qBittorrent in its own stopped window, so that window sets the disk backend (#983, #1061). */
final class QbittorrentFrontendDiskIoTypeTest extends TemporaryHomeRootTestCase
{
    private function fixture(?string $version): array
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-diskio-');
        $store = $this->pmssEnsureDir($home.'/store');
        $projection = $this->pmssWriteFile($home.'/torrentClients.json', json_encode(['versions' => [
            ['client' => 'qbittorrent', 'version' => '4.6.7-lt2.0', 'compatFamily' => '4.6.7'],
            ['client' => 'qbittorrent', 'version' => '5.2.4-lt2.0', 'compatFamily' => '5.2.4'],
            ['client' => 'qbittorrent', 'version' => '5.2.4-lt1.2', 'compatFamily' => 'lt1.2'],
            ['client' => 'qbittorrent', 'version' => '5.3.0-lt2.1', 'compatFamily' => '5.3.0'],
        ]]));
        $this->pmssEnsureDir($home.'/bin/qbittorrent');
        if ($version !== null) $this->pmssCreateSymlinkOrSkip($store.'/qbittorrent/'.$version, $home.'/bin/qbittorrent/default');
        return [$home, ['store' => $store, 'projection' => $projection]];
    }

    public function testSelectedFamilyMatchesTheRootRule(): void
    {
        $families = ['4.6.7-lt2.0' => '4.6.7', '5.2.4-lt2.0' => '5.2.4', '5.2.4-lt1.2' => 'lt1.2', '5.3.0-lt2.1' => '5.3.0'];
        foreach ($families as $version => $family) {
            list($home, $ops) = $this->fixture($version);
            $this->assertSame((string) \pmssQbittorrentDiskIoType($family), \pmssQbittorrentFrontendDiskIoType($home, $ops), $version);
        }
        list($home, $ops) = $this->fixture(null);
        $this->assertSame(\pmssQbittorrentDiskIoType(''), \pmssQbittorrentFrontendDiskIoType($home, $ops));
        list($home, $ops) = $this->fixture('unknown-build');
        $this->assertSame('Posix', \pmssQbittorrentFrontendDiskIoType($home, $ops));
    }

    public function testApplySetsReplacesOrRemovesOnlyThatKey(): void
    {
        $config = "[BitTorrent]\nSession\\DiskIOType=Posix\nSession\\Port=1234\n\n[Preferences]\nWebUI\\Port=2345\n";
        $this->assertSame(str_replace('=Posix', '=SimplePreadPwrite', $config),
            \pmssQbittorrentFrontendDiskIoTypeApply($config, 'SimplePreadPwrite'));
        $this->assertSame($config, \pmssQbittorrentFrontendDiskIoTypeApply($config, 'Posix'));
        $this->assertSame(str_replace("Session\\DiskIOType=Posix\n", '', $config), \pmssQbittorrentFrontendDiskIoTypeApply($config, ''));
        $this->assertStringContainsString("[BitTorrent]\nSession\\DiskIOType=Posix",
            \pmssQbittorrentFrontendDiskIoTypeApply("[Preferences]\nWebUI\\Port=2345\n", 'Posix'));
    }

    public function testStoppedRestoreGivesASwitchedAccountTheThreadedBackend(): void
    {
        foreach (['5.2.4-lt2.0' => 'SimplePreadPwrite', '5.3.0-lt2.1' => 'SimplePreadPwrite', '4.6.7-lt2.0' => 'Posix'] as $version => $type) {
            list($home, $ops) = $this->fixture($version);
            $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf',
                "[BitTorrent]\nSession\\DiskIOType=Posix\n\n[Meta]\nMigrationVersion=4\n\n[Preferences]\nWebUI\\Port=2345\n");
            $this->assertTrue(\pmssQbittorrentFrontendRestore($home, $ops + [
                'stopped' => static function (): bool { return true; },
                'build' => preg_replace('/-.*/', '', $version),
                'write' => static function ($path, $content): bool { return file_put_contents($path, $content) === strlen($content); },
            ]));
            $values = \pmssQbittorrentConfigValues(file_get_contents($path));
            $this->assertSame($type, $values['BitTorrent']['Session\\DiskIOType'], $version);
            $this->assertSame('2345', $values['Preferences']['WebUI\\Port']);
        }
        list($home, $ops) = $this->fixture('5.2.4-lt1.2');
        $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', "[BitTorrent]\nSession\\DiskIOType=Posix\nOther=1\n");
        $this->assertTrue(\pmssQbittorrentFrontendRestore($home, $ops + ['stopped' => static function (): bool { return true; }, 'build' => '5.2.4',
            'write' => static function ($path, $content): bool { return file_put_contents($path, $content) === strlen($content); }]));
        $this->assertStringNotContainsString('DiskIOType', file_get_contents($path));
    }

    public function testConfigureSwitchStartsTheNewBuildWithItsBackendAndRollbackRestoresTheOld(): void
    {
        require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClients.php';
        foreach ([[true, 'SimplePreadPwrite'], [false, 'Posix']] as list($starts, $expected)) {
            list($home, $ops) = $this->fixture(null);
            $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', "[BitTorrent]\nSession\\DiskIOType=Posix\n");
            $seen = [];
            $result = \pmssConfigureTorrentClientSwitch($home, ['client' => 'qbittorrent', 'version' => '5.2.4-lt2.0'], $ops + [
                'versions' => ['5.2.4-lt2.0' => ['kind' => 'static']],
                'resolve' => static function () { return '/fixture/qbittorrent-nox'; },
                'runningVersion' => static function () { return 'system'; },
                'backup' => static function (): array { return ['ok' => true]; },
                'stop' => static function (): bool { return true; },
                'stopped' => static function (): bool { return true; },
                'repoint' => static function ($home, $version) use ($ops): bool {
                    @unlink($home.'/bin/qbittorrent/default');
                    return $version === 'system' || symlink($ops['store'].'/qbittorrent/'.$version, $home.'/bin/qbittorrent/default');
                },
                // The new build "starts" by recording the backend it would read; the first start fails when $starts is false.
                'start' => static function ($home, $version) use ($path, &$seen, $starts): bool {
                    $seen[$version] = \pmssQbittorrentConfigValues(file_get_contents($path))['BitTorrent']['Session\\DiskIOType'] ?? '(none)';
                    return $starts || $version === 'system';
                },
                'write' => static function ($path, $content): bool { return file_put_contents($path, $content) === strlen($content); },
            ]);
            $this->assertSame($starts, $result['ok']);
            $this->assertSame('SimplePreadPwrite', $seen['5.2.4-lt2.0']);
            if (!$starts) $this->assertSame('Posix', $seen['system']);
            $this->assertSame($expected, \pmssQbittorrentConfigValues(file_get_contents($path))['BitTorrent']['Session\\DiskIOType']);
        }
    }

    public function testRunningDaemonKeepsItsConfigUntouched(): void
    {
        list($home, $ops) = $this->fixture('5.2.4-lt2.0');
        $config = "[BitTorrent]\nSession\\DiskIOType=Posix\n";
        $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', $config);
        $this->assertFalse(\pmssQbittorrentFrontendRestore($home, $ops + ['stopped' => static function (): bool { return false; },
            'notice' => static function (): void {}]));
        $this->assertSame($config, file_get_contents($path));
    }
}
