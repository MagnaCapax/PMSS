<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/qbittorrent.php';
require_once dirname(__DIR__, 2).'/user/torrentPort.php';

final class QbittorrentConfigCharacterizationTest extends TestCase
{
    /** @var string */
    private $homeRoot = '';

    protected function setUp(): void
    {
        $this->homeRoot = $this->pmssMakeTempDir('pmss-qbt-characterization-');
        $this->pmssTrackEnvOverrides(['PMSS_HOME_DIR' => $this->homeRoot], true);
    }

    public function testPasswordSyncRewritesExistingPasswordLineInPlace(): void
    {
        $configPath = $this->pmssWriteRelativeFile($this->homeRoot, 'alice/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nWebUI\\Password_PBKDF2=@ByteArray(old:hash)\nLocale=en\n");

        $this->assertTrue(\pmssUpdateQbittorrentPassword('alice', 'secret'));

        $lines = explode("\n", rtrim((string) file_get_contents($configPath), "\n"));
        $this->assertSame('[Preferences]', $lines[0]);
        $this->assertMatches('/^WebUI\\\\Password_PBKDF2=@ByteArray\([A-Za-z0-9+\/=]+:[A-Za-z0-9+\/=]+\)$/', $lines[1]);
        $this->assertSame('Locale=en', $lines[2]);
        $this->assertSame(1, preg_match_all('/^WebUI\\\\Password_PBKDF2=/m', implode("\n", $lines)));

        $matches = [];
        $this->assertSame(1, preg_match('/@ByteArray\(([^:]+):([^\)]+)\)/', $lines[1], $matches));
        $salt = base64_decode($matches[1], true);
        $storedHash = base64_decode($matches[2], true);
        $this->assertTrue(is_string($salt) && is_string($storedHash));
        $this->assertTrue(hash_equals($storedHash, hash_pbkdf2('sha512', 'secret', $salt, 100000, 64, true)));
    }

    public function testPasswordSyncAddsPasswordLineInsidePreferencesSection(): void
    {
        $configPath = $this->pmssWriteRelativeFile($this->homeRoot, 'alice/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nLocale=en\n\n[BitTorrent]\nSession\\DiskCacheSize=128\n");

        $this->assertTrue(\pmssUpdateQbittorrentPassword('alice', 'secret'));

        $lines = explode("\n", rtrim((string) file_get_contents($configPath), "\n"));
        $this->assertSame('[Preferences]', $lines[0]);
        $this->assertSame('Locale=en', $lines[1]);
        $this->assertMatches('/^WebUI\\\\Password_PBKDF2=@ByteArray\([A-Za-z0-9+\/=]+:[A-Za-z0-9+\/=]+\)$/', $lines[2]);
        $this->assertSame('', $lines[3]);
        $this->assertSame('[BitTorrent]', $lines[4]);
    }

    public function testUploadThrottleRemovalKeepsRemainingPreferencesSnapshot(): void
    {
        $configPath = $this->pmssWriteRelativeFile($this->homeRoot, 'alice/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nConnection\\GlobalUPLimit=512\nLocale=en\n");

        $this->assertTrue(\pmssQbittorrentApplyUploadThrottle('alice', 0));
        $this->assertSame("[Preferences]\nLocale=en\n", (string) file_get_contents($configPath));
    }

    public function testPortRepairKeepsOtherLinesAndUsesSharedWriterSnapshot(): void
    {
        $home = $this->homeRoot.'/alice';
        @mkdir($home, 0755, true);
        $this->pmssWriteRelativeFile($this->homeRoot, 'alice/.qbittorrentPort', "45678\n");
        $configPath = $this->pmssWriteRelativeFile($this->homeRoot, 'alice/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nWebUI\\Port=12345\nLocale=en\n");

        $this->assertTrue(\pmssQbittorrentPortEnsure('alice', $home));
        $this->assertSame(
            "[Preferences]\nWebUI\\Port=45678\nLocale=en\n",
            (string) file_get_contents($configPath)
        );
    }

}
