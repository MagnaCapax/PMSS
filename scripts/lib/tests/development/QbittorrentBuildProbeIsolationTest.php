<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/qbittorrentBuildProbe.php';
require_once dirname(__DIR__, 2).'/user/qbittorrentAccountProcess.php';

/**
 * qbittorrent-nox --version loads and saves the profile's qBittorrent.conf (4.5.2, 5.2.4 and 5.3.0 measured), so the
 * build probe must never run against the account's own profile, and a running probe is not the account's daemon.
 */
final class QbittorrentBuildProbeIsolationTest extends TestCase
{
    public function testProbeRunsInAPrivateProfileAndLeavesTheAccountConfigAlone(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-probe-home-');
        $config = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', "[Meta]\nMigrationVersion=6\n");
        $bin = $this->pmssMakeTempDir('pmss-qbt-probe-bin-').'/qbittorrent-nox';
        $this->pmssWriteExecutableFile($bin,
            "#!/bin/sh\nmkdir -p \"\$HOME/.config/qBittorrent\" && echo '[Meta]' > \"\$HOME/.config/qBittorrent/qBittorrent.conf\"\necho 'qBittorrent v5.3.0'\n");
        $this->pmssTrackEnvOverrides(['HOME' => $home]);
        $before = glob(rtrim(sys_get_temp_dir(), '/').'/pmss-qbt-probe-*') ?: [];

        $this->assertSame("qBittorrent v5.3.0\n", \pmssQbittorrentBuildProbe($bin));
        $this->assertSame("[Meta]\nMigrationVersion=6\n", (string) file_get_contents($config));
        $this->assertSame($before, glob(rtrim(sys_get_temp_dir(), '/').'/pmss-qbt-probe-*') ?: [], 'probe profile removed');
    }

    public function testAccountPidsIgnoreARunningVersionProbe(): void
    {
        $proc = $this->pmssMakeTempDir('pmss-qbt-proc-');
        $uid = posix_geteuid();
        foreach ([101 => "/x/qbittorrent-nox\0--version\0", 102 => "/x/qbittorrent-nox\0-d\0", 103 => "/x/qbittorrent-nox\0"] as $pid => $cmdline) {
            $this->pmssWriteFiles($proc.'/'.$pid, ['comm' => "qbittorrent-nox\n", 'cmdline' => $cmdline,
                'status' => "Name:\tqbittorrent-nox\nUid:\t".$uid."\t".$uid."\t".$uid."\t".$uid."\n"]);
        }
        $pids = \pmssQbittorrentAccountPids($proc, $uid);
        sort($pids);
        $this->assertSame([102, 103], $pids);
    }
}
