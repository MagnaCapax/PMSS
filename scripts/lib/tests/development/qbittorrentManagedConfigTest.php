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

    public function testApplyManagedConfigRestoresWebUiSafetyDefaults(): void
    {
        $configPath = $this->pmssWriteRelativeFile(
            $this->homeRoot,
            'alice/.config/qBittorrent/qBittorrent.conf',
            "[Preferences]\nWebUI\\CSRFProtection=false\nWebUI\\ClickjackingProtection=false\nWebUI\\HostHeaderValidation=false\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('alice'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsAllStrings(["WebUI\\CSRFProtection=true\n", "WebUI\\ClickjackingProtection=true\n", "WebUI\\HostHeaderValidation=false\n"], $updated);
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
        $this->assertStringContainsAllStrings(["[BitTorrent]\n", "Session\\DiskCacheSize=128\n", "[Preferences]\n", "Bittorrent\\MaxConnecs=300\n", "WebUI\\HostHeaderValidation=false\n"], $updated);
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
            "[Preferences]\nConnection\\GlobalUPLimit=900\n"
        );
        chmod($configPath, 0640);

        require_once dirname(__DIR__, 2).'/user/qbittorrentThrottleAccount.php';
        $this->assertTrue(pmssQbittorrentThrottleAccount($this->homeRoot.'/alice', 512, true, [
            'build' => '4.3.9',
            'recordWrite' => static function ($path, $value): bool { return file_put_contents($path, $value) === strlen($value); },
            'accountPids' => static function (): array { return []; },
            'restore' => static function (): bool { return true; },
        ]));

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

    public function testMigratedThrottleComparisonDoesNotWriteBeforeShutdown(): void
    {
        // ADR 0182 keys the throttle on the discovered build; pin a 4.4+ build so the host's system build cannot decide.
        $bin = $this->pmssMakeTempDir('pmss-qbt-bin-');
        $this->pmssWriteFile($bin.'/qbittorrent-nox', "#!/bin/sh\necho 'qBittorrent v4.6.7'\n");
        chmod($bin.'/qbittorrent-nox', 0755);
        $this->pmssTrackEnvOverrides(['PATH' => $bin.':'.(string) getenv('PATH')]);
        $before = "[Meta]\nMigrationVersion=2\n[BitTorrent]\nSession\\GlobalUPSpeedLimit=10\n[Preferences]\nLocale=en\n";
        $path = $this->pmssWriteRelativeFile($this->homeRoot, 'alice/.config/qBittorrent/qBittorrent.conf', $before);
        $this->pmssWriteRelativeFile($this->homeRoot, 'alice/.torrentThrottle', "512\n");
        $this->assertTrue(pmssQbittorrentUploadThrottleNeedsUpdate('alice'));
        $this->assertSame($before, file_get_contents($path));

        // Simulate the daemon saving its old settings on SIGTERM before PMSS writes.
        file_put_contents($path, $before."WebUI\\Port=23456\n");
        $this->assertTrue(pmssQbittorrentApplyUploadThrottle('alice'));
        $updated = file_get_contents($path);
        $this->assertStringContainsString("Session\\GlobalUPSpeedLimit=512\n", $updated);
        $this->assertStringContainsString("WebUI\\Port=23456\n", $updated);
        $this->assertStringNotContainsString('Connection\\GlobalUPLimit', $updated);
        $this->assertFalse(pmssQbittorrentUploadThrottleNeedsUpdate('alice'));
        $this->assertFalse(pmssQbittorrentApplyUploadThrottle('alice'));
    }

    public function testMigratedThrottleIgnoresObsoleteKeyAndSurvivesDaemonSave(): void
    {
        foreach ([2, 9, 12] as $migration) {
            $config = "[Meta]\nMigrationVersion=".$migration."\n[BitTorrent]\nSession\\GlobalUPSpeedLimit=512\n";
            $this->assertSame($config, pmssQbittorrentUploadThrottleConfig($config, 512));
            $obsolete = $config."[Preferences]\nConnection\\GlobalUPLimit=10\n";
            $this->assertSame($obsolete, pmssQbittorrentUploadThrottleConfig($obsolete, 512));
        }
    }

    public function testUninitializedAndLegacyConfigsSeedBothThrottleSchemas(): void
    {
        foreach (["[Preferences]\nLocale=en\n", "[Meta]\nMigrationVersion=1\n[Preferences]\n"] as $config) {
            $updated = pmssQbittorrentUploadThrottleConfig($config, 500);
            $parsed = parse_ini_string($updated, true, INI_SCANNER_RAW);
            $this->assertSame('500', $parsed['BitTorrent']['Session\\GlobalUPSpeedLimit']);
            $this->assertSame('500', $parsed['Preferences']['Connection\\GlobalUPLimit']);
            $this->assertSame($updated, pmssQbittorrentUploadThrottleConfig($updated, 500));
        }
    }

    public function testThrottleReleaseLeavesSavedUnlimitedValuesAlone(): void
    {
        $config = "[Meta]\nMigrationVersion=9\n[BitTorrent]\nSession\\GlobalUPSpeedLimit=512\n";
        $unlimited = str_replace('=512', '=0', $config);
        $this->assertSame($unlimited, pmssQbittorrentUploadThrottleConfig($config, 0));
        foreach ([null, 0] as $throttle) {
            $this->assertSame($unlimited, pmssQbittorrentUploadThrottleConfig($unlimited, $throttle));
            $legacy = $unlimited."[Preferences]\nConnection\\GlobalUPLimit=0\n";
            $this->assertSame($legacy, pmssQbittorrentUploadThrottleConfig($legacy, $throttle));
            $this->assertSame("[Preferences]\n", pmssQbittorrentUploadThrottleConfig("[Preferences]\n", $throttle));
        }
    }

    public function testThrottleComparisonSkipsMissingMalformedAndLinkedConfigs(): void
    {
        $this->pmssWriteRelativeFile($this->homeRoot, 'alice/.torrentThrottle', "500\n");
        $this->assertFalse(pmssQbittorrentUploadThrottleNeedsUpdate('alice'));
        $path = $this->pmssWriteRelativeFile($this->homeRoot, 'alice/.config/qBittorrent/qBittorrent.conf', "[invalid\n");
        $this->assertFalse(pmssQbittorrentUploadThrottleNeedsUpdate('alice'));
        $this->assertFalse(pmssQbittorrentApplyUploadThrottle('alice'));
        unlink($path);
        $target = $this->pmssWriteRelativeFile($this->homeRoot, 'target.conf', "[Preferences]\n");
        symlink($target, $path);
        $this->assertFalse(pmssQbittorrentUploadThrottleNeedsUpdate('alice'));
        $this->assertFalse(pmssQbittorrentApplyUploadThrottle('alice'));
        $this->assertSame("[Preferences]\n", file_get_contents($target));
    }

}
