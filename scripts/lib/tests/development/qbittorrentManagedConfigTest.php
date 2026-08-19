<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/qbittorrent.php';

class QbittorrentManagedConfigTest extends TestCase
{
    /** @var string */
    private $homeRoot = '';

    protected function setUp(): void
    {
        $this->homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-qbittorrent-managed-');
        $this->pmssTrackEnvOverrides($this->pmssTestModeEnv(), true);
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
        $configPath = $this->pmssWriteRelativeFile(
            $this->homeRoot,
            'alice/.config/qBittorrent/qBittorrent.conf',
            "[BitTorrent]\nSession\\DiskCacheSize=16384\nSession\\MaxConnections=9999\n\n[Preferences]\nDownloads\\DiskWriteCacheSize=16384\nBittorrent\\MaxConnecs=9999\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('alice'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsAllStrings(["Session\\DiskCacheSize=128\n", "Session\\MaxConnections=300\n", "Downloads\\DiskWriteCacheSize=128\n", "Bittorrent\\MaxConnecs=300\n"], $updated);
    }

    public function testApplyManagedConfigRestoresWebUiSafetyFlags(): void
    {
        $configPath = $this->pmssWriteRelativeFile(
            $this->homeRoot,
            'alice/.config/qBittorrent/qBittorrent.conf',
            "[Preferences]\nWebUI\\CSRFProtection=false\nWebUI\\ClickjackingProtection=false\nWebUI\\HostHeaderValidation=false\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('alice'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsAllStrings(["WebUI\\CSRFProtection=true\n", "WebUI\\ClickjackingProtection=true\n", "WebUI\\HostHeaderValidation=true\n"], $updated);
    }

    public function testApplyManagedConfigPreservesUserOwnedSettings(): void
    {
        $configPath = $this->pmssWriteRelativeFile(
            $this->homeRoot,
            'alice/.config/qBittorrent/qBittorrent.conf',
            "[Preferences]\nGeneral\\Locale=fi\nWebUI\\Port=23456\nDownloads\\DiskWriteCacheSize=8192\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('alice'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsAllStrings(["General\\Locale=fi\n", "WebUI\\Port=23456\n", "Downloads\\DiskWriteCacheSize=128\n"], $updated);
    }

    public function testApplyManagedConfigRestoresMissingManagedKeysWithinExistingSections(): void
    {
        $configPath = $this->pmssWriteRelativeFile(
            $this->homeRoot,
            'alice/.config/qBittorrent/qBittorrent.conf',
            "[BitTorrent]\nSession\\DiskIOType=MemoryMappedFiles\n\n[Preferences]\nLocale=en\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('alice'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsAllStrings(["Session\\DiskIOType=Posix\n", "Session\\AsyncIOThreadsCount=4\n", "Downloads\\PreAllocation=true\n"], $updated);
    }

    public function testApplyManagedConfigCreatesMissingManagedSections(): void
    {
        $configPath = $this->pmssWriteRelativeFile(
            $this->homeRoot,
            'alice/.config/qBittorrent/qBittorrent.conf',
            "[Application]\nMemoryWorkingSetLimit=2048\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('alice'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsAllStrings(["[BitTorrent]\n", "Session\\DiskCacheSize=128\n", "[Preferences]\n", "Bittorrent\\MaxConnecs=300\n", "WebUI\\HostHeaderValidation=true\n"], $updated);
    }

    public function testApplyManagedConfigRejectsSymlinkTarget(): void
    {
        $configDir = $this->homeRoot.'/alice/.config/qBittorrent';
        @mkdir($configDir, 0755, true);
        $target = $configDir.'/qBittorrent.conf';
        @symlink($this->homeRoot.'/missing-target', $target);

        $this->assertFalse(pmssQbittorrentApplyManagedConfig('alice'));
    }

    public function testApplyUploadThrottlePreservesFileMode(): void
    {
        $configPath = $this->pmssWriteRelativeFile(
            $this->homeRoot,
            'alice/.config/qBittorrent/qBittorrent.conf',
            "[Preferences]\nConnection\\GlobalUPLimit=10\n"
        );
        chmod($configPath, 0640);

        $this->assertTrue(pmssQbittorrentApplyUploadThrottle('alice', 512));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsString("Connection\\GlobalUPLimit=512\n", $updated);
        $this->assertSame(0640, fileperms($configPath) & 0777);
    }

    public function testApplyUploadThrottleRejectsSymlinkedHomeRoot(): void
    {
        $realHomeRoot = $this->homeRoot.'/real-home';
        $linkedHomeRoot = $this->homeRoot.'/linked-home';
        $configPath = $this->pmssWriteRelativeFile($realHomeRoot, 'alice/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nConnection\\GlobalUPLimit=10\n");
        symlink($realHomeRoot, $linkedHomeRoot);
        $this->pmssTrackHomeRoot($linkedHomeRoot);

        $this->assertFalse(pmssQbittorrentApplyUploadThrottle('alice', 512));
        $this->assertSame(
            "[Preferences]\nConnection\\GlobalUPLimit=10\n",
            (string) file_get_contents($configPath)
        );
    }

}
