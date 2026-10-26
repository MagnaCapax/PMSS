<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentStoppedConfig.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentStoppedConfig.php';

/** Version probes are injected; no real client or service is launched. */
final class QbittorrentBuildTest extends TestCase
{
    public function testSystemAndCustomProbeCacheIsKeyedByRealpathAndMtimeOnBothSides(): void
    {
        foreach (['pmssQbittorrentBuildVersion', 'pmssQbittorrentFrontendBuildVersion'] as $version) {
            $home = $this->pmssMakeTempDir('pmss-qbt-build-');
            $binary = $this->pmssWriteFile($home.'/bin/custom/qbittorrent-nox', 'fixture');
            $link = $home.'/bin/linked';
            $this->pmssCreateSymlinkOrSkip($binary, $link);
            $probes = 0;
            $ops = ['probe' => static function ($path) use (&$probes, $binary): string {
                if ($path !== $binary) throw new \RuntimeException('Probe did not use the canonical binary');
                $probes++; return 'qBittorrent v4.2.5';
            }, 'write' => 'pmssConfigureFileWrite'];
            $this->assertSame('4.2.5', $version($home, $binary, $ops));
            $this->assertSame('4.2.5', $version($home, $link, $ops));
            $this->assertSame(1, $probes);
            touch($binary, time() + 10); clearstatcache(true, $binary);
            $this->assertSame('4.2.5', $version($home, $binary, $ops));
            $this->assertSame(2, $probes);
            foreach (glob($home.'/.config/pmss/torrentClients/qbittorrent.version-*') as $cache)
                $this->assertSame(0600, fileperms($cache) & 0777);
        }
    }

    public function testStoreVersionComesFromThePublishedEntryWithoutProbing(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-store-version-');
        $store = $home.'/store';
        $binary = $this->pmssWriteFile($store.'/qbittorrent/4.3.9-lt1.2/qbittorrent-nox', 'fixture');
        $projection = $this->pmssWriteFile($home.'/projection.json', json_encode(['versions' => [
            ['client' => 'qbittorrent', 'version' => '4.3.9-lt1.2', 'kind' => 'static'],
        ]]));
        foreach (['pmssQbittorrentBuildVersion', 'pmssQbittorrentFrontendBuildVersion'] as $version)
            $this->assertSame('4.3.9', $version($home, $binary, ['store' => $store, 'projection' => $projection,
                'probe' => static function (): void { throw new \RuntimeException('Store build was probed'); }]));
    }

    public function testUnreadableVersionFallsBackToMetaAndDoesNotInventABuild(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-unknown-version-');
        $binary = $this->pmssWriteFile($home.'/qbittorrent-nox', 'fixture');
        foreach ([null, '', 'unrelated 5.3.0', 'qBittorrent broken'] as $output)
            foreach (['pmssQbittorrentBuildVersion', 'pmssQbittorrentFrontendBuildVersion'] as $version) {
                $number = $version($home, $binary, ['probe' => static function () use ($output) { return $output; }]);
                $this->assertSame(null, $number);
                $this->assertSame(['WebUI', ''], \pmssQbittorrentWebUiSection("[Meta]\nMigrationVersion=12\n", $number));
            }
    }

    public function testLastBuildRecordsActualSystemAndCustomVersionsAndKeepsTrackOneLabels(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-last-build-');
        $dir = $home.'/.config/pmss/torrentClients';
        $this->pmssWriteFile($dir.'/qbittorrent.lastRun', "4.3.9-lt2.0\n");
        $this->assertSame('4.3.9', \pmssQbittorrentFrontendLastBuild($home));
        \pmssQbittorrentFrontendBuildRecord($home, '5.3.0');
        $this->assertSame('5.3.0', \pmssQbittorrentLastBuild($home));
        $this->assertSame("4.3.9-lt2.0\n", file_get_contents($dir.'/qbittorrent.lastRun'));
        \pmssQbittorrentFrontendBuildRecord($home, null);
        $this->assertSame('5.3.0', \pmssQbittorrentFrontendLastBuild($home));
    }
}
