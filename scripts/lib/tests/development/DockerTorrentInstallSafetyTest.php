<?php
/** Hermetic first-start file safety checks for customer-local torrent installs. */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/dockerInstallLsio.php';

final class DockerTorrentInstallSafetyTest extends TestCase
{
    public function testPrivateWriterRejectsLinksDirectoriesAndMultipleLinks(): void
    {
        $home = $this->pmssMakeTempDir('pmss-docker-torrent-safety-');
        $outside = $this->pmssWriteFile($home.'/outside', 'preserved');
        $link = $home.'/linked';
        $this->pmssCreateSymlinkOrSkip($outside, $link);
        $dangling = $home.'/dangling';
        $this->pmssCreateSymlinkOrSkip($home.'/absent', $dangling);
        mkdir($home.'/directory');
        $this->assertTrue(link($outside, $home.'/hardlink'));
        foreach ([$link, $dangling, $home.'/directory', $home.'/hardlink'] as $target) {
            $this->assertFalse(\pmssDockerInstallLsioWritePrivateFile($target, 'secret'));
        }
        $this->assertSame('preserved', file_get_contents($outside));
        $this->assertFalse(file_exists($home.'/absent'));
        $this->assertSame([], glob($home.'/.pmss-lsio-*'));
    }

    public function testPrivateWriterRejectsForeignOwnedTarget(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new SkipTest('Foreign-owner fixture requires root on the testing machine');
        }
        $home = $this->pmssMakeTempDir('pmss-docker-torrent-owner-');
        $target = $this->pmssWriteFile($home.'/credentials', 'preserved');
        $this->assertTrue(chown($target, 65534));
        try {
            clearstatcache();
            $this->assertFalse(\pmssDockerInstallLsioWritePrivateFile($target, 'secret'));
            $this->assertSame('preserved', file_get_contents($target));
        } finally {
            chown($target, 0);
        }
    }

    public function testSeedRejectsRedirectedConfigDirectory(): void
    {
        $home = $this->pmssMakeTempDir('pmss-docker-torrent-directory-');
        $outside = $this->pmssMakeTempDir('pmss-docker-torrent-outside-');
        $this->pmssEnsureDir($home.'/docker/qbittorrent/config');
        $this->pmssCreateSymlinkOrSkip($outside, $home.'/docker/qbittorrent/config/qBittorrent');
        $this->assertFalse(\pmssDockerInstallLsioQbittorrentSeed($home, 23002));
        $this->assertSame(['.', '..'], scandir($outside));
        $this->assertFalse(file_exists($home.'/docker/qbittorrent/pmss-credentials.env'));
    }

    public function testSeedRejectsLinkedConfigAndCredentialTargets(): void
    {
        foreach (['config/qBittorrent/qBittorrent.conf', 'pmss-credentials.env'] as $suffix) {
            $home = $this->pmssMakeTempDir('pmss-docker-torrent-links-');
            $directory = $home.'/docker/qbittorrent';
            $this->pmssEnsureDir($directory.'/config/qBittorrent');
            $outside = $this->pmssWriteFile($home.'/outside', 'preserved');
            $this->pmssCreateSymlinkOrSkip($outside, $directory.'/'.$suffix);
            $this->assertFalse(\pmssDockerInstallLsioQbittorrentSeed($home, 23002));
            $this->assertSame('preserved', file_get_contents($outside));
            $this->assertSame([], glob($directory.'/.pmss-lsio-*'));
        }
    }

    public function testConfigHashesUseIndependentSaltsAndVerify(): void
    {
        $previous = null;
        foreach ([1, 23002, 38000, 65534, 65535] as $peer) {
            $config = \pmssDockerInstallLsioQbittorrentConfig($peer, 'service-user', 'service-password');
            $this->assertTrue(preg_match('/@ByteArray\(([^:]+):([^)]*)\)/', $config, $match) === 1);
            $salt = base64_decode($match[1], true);
            $this->assertSame(16, strlen($salt));
            $this->assertSame(hash_pbkdf2('sha512', 'service-password', $salt, 100000, 64, true), base64_decode($match[2], true));
            $this->assertTrue($previous !== $salt);
            $previous = $salt;
            $this->assertStringNotContainsString('service-password', $config);
            $this->assertStringContainsString('Connection\PortRangeMin='.$peer."\n", $config);
        }
    }

    public function testTorrentCommandCannotFallBackToDefaultPeerPort(): void
    {
        foreach (['qbittorrent', 'transmission'] as $app) {
            $spec = \pmssDockerInstallLsioAppSpec($app, '/unused', true);
            foreach ([null, 0, -1, 65536, '23002'] as $invalid) {
                $spec['peerPort'] = $invalid;
                $this->assertThrows(\InvalidArgumentException::class, static function () use ($app, $spec): void {
                    \pmssDockerInstallLsioDockerRunCommand($app, '23001', 'UTC', $spec);
                }, 'reserved peer port');
            }
        }
    }
}
