<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/qbittorrent.php';

class QbittorrentManagedConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private $envBackup = [];

    /** @var string */
    private $homeRoot = '';

    protected function setUp(): void
    {
        $this->envBackup = $this->pmssCaptureEnv(['PMSS_HOME_DIR', 'PMSS_TEST_MODE']);
        $this->homeRoot = $this->pmssMakeTempDir('pmss-qbittorrent-managed-');
        putenv('PMSS_HOME_DIR='.$this->homeRoot);
        putenv('PMSS_TEST_MODE=1');
    }

    protected function tearDown(): void
    {
        $this->pmssRestoreEnvMap($this->envBackup, true);
    }

    public function testConfigPathHonoursManagedHomeOverride(): void
    {
        $this->assertSame(
            $this->homeRoot.'/alice/.config/qBittorrent/qBittorrent.conf',
            pmssQbittorrentConfigPath('alice')
        );
    }

    public function testApplyManagedConfigRewritesDiskCacheAndConnectionCaps(): void
    {
        $configPath = $this->writeConfig(
            "[BitTorrent]\nSession\\DiskCacheSize=16384\nSession\\MaxConnections=9999\n\n[Preferences]\nDownloads\\DiskWriteCacheSize=16384\nBittorrent\\MaxConnecs=9999\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('alice'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsString("Session\\DiskCacheSize=128\n", $updated);
        $this->assertStringContainsString("Session\\MaxConnections=300\n", $updated);
        $this->assertStringContainsString("Downloads\\DiskWriteCacheSize=128\n", $updated);
        $this->assertStringContainsString("Bittorrent\\MaxConnecs=300\n", $updated);
    }

    public function testApplyManagedConfigRestoresWebUiSafetyFlags(): void
    {
        $configPath = $this->writeConfig(
            "[Preferences]\nWebUI\\CSRFProtection=true\nWebUI\\ClickjackingProtection=true\nWebUI\\HostHeaderValidation=true\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('alice'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsString("WebUI\\CSRFProtection=false\n", $updated);
        $this->assertStringContainsString("WebUI\\ClickjackingProtection=false\n", $updated);
        $this->assertStringContainsString("WebUI\\HostHeaderValidation=false\n", $updated);
    }

    public function testApplyManagedConfigPreservesUserOwnedSettings(): void
    {
        $configPath = $this->writeConfig(
            "[Preferences]\nGeneral\\Locale=fi\nWebUI\\Port=23456\nDownloads\\DiskWriteCacheSize=8192\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('alice'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsString("General\\Locale=fi\n", $updated);
        $this->assertStringContainsString("WebUI\\Port=23456\n", $updated);
        $this->assertStringContainsString("Downloads\\DiskWriteCacheSize=128\n", $updated);
    }

    public function testApplyManagedConfigLeavesMissingManagedKeysUntouched(): void
    {
        $configPath = $this->writeConfig(
            "[BitTorrent]\nSession\\DiskIOType=MemoryMappedFiles\n\n[Preferences]\nLocale=en\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('alice'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsString("Session\\DiskIOType=Posix\n", $updated);
        $this->pmssAssertStringNotContainsString("Session\\AsyncIOThreadsCount=4\n", $updated);
        $this->pmssAssertStringNotContainsString("Downloads\\PreAllocation=false\n", $updated);
    }

    public function testApplyManagedConfigRejectsSymlinkTarget(): void
    {
        $configDir = $this->homeRoot.'/alice/.config/qBittorrent';
        @mkdir($configDir, 0755, true);
        $target = $configDir.'/qBittorrent.conf';
        @symlink($this->homeRoot.'/missing-target', $target);

        $this->assertFalse(pmssQbittorrentApplyManagedConfig('alice'));
    }

    private function writeConfig(string $contents): string
    {
        $configDir = $this->homeRoot.'/alice/.config/qBittorrent';
        @mkdir($configDir, 0755, true);
        $configPath = $configDir.'/qBittorrent.conf';
        file_put_contents($configPath, $contents);

        return $configPath;
    }
}
