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

    /** Record the fixture account's upload cap in a root store fixture (ADR 0213: the home copy is display only). */
    private function storeUploadCap(int $kib): void
    {
        $configDir = $this->pmssMakeTempDir('pmss-qbittorrent-config-');
        $this->pmssEnsureDir($configDir.'/users');
        file_put_contents($configDir.'/users/fixtur01.json', json_encode(['ramMiB' => 512, 'rtorrentPort' => 0, 'quota' => 5, 'quotaBurst' => 6, 'uploadThrottleKib' => $kib]));
        $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $configDir]);
    }

    public function testConfigPathHonoursManagedHomeOverride(): void
    {
        $this->assertSame(
            $this->homeRoot.'/fixtur01/.config/qBittorrent/qBittorrent.conf',
            pmssQbittorrentConfigPath('fixtur01')
        );
    }

    public function testApplyManagedConfigRewritesDiskCacheAndConnectionCaps(): void
    {
        $configPath = $this->pmssWriteRelativeFile(
            $this->homeRoot,
            'fixtur01/.config/qBittorrent/qBittorrent.conf',
            "[BitTorrent]\nSession\\DiskCacheSize=16384\nSession\\MaxConnections=9999\n\n[Preferences]\nDownloads\\DiskWriteCacheSize=16384\nBittorrent\\MaxConnecs=9999\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('fixtur01'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsAllStrings(["Session\\DiskCacheSize=128\n", "Session\\MaxConnections=300\n", "Downloads\\DiskWriteCacheSize=128\n", "Bittorrent\\MaxConnecs=300\n"], $updated);
    }

    public function testApplyManagedConfigRestoresWebUiSafetyDefaults(): void
    {
        $configPath = $this->pmssWriteRelativeFile(
            $this->homeRoot,
            'fixtur01/.config/qBittorrent/qBittorrent.conf',
            "[Preferences]\nWebUI\\CSRFProtection=false\nWebUI\\ClickjackingProtection=false\nWebUI\\HostHeaderValidation=false\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('fixtur01'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsAllStrings(["WebUI\\CSRFProtection=true\n", "WebUI\\ClickjackingProtection=true\n", "WebUI\\HostHeaderValidation=false\n"], $updated);
    }

    public function testApplyManagedConfigPreservesUserOwnedSettings(): void
    {
        $configPath = $this->pmssWriteRelativeFile(
            $this->homeRoot,
            'fixtur01/.config/qBittorrent/qBittorrent.conf',
            "[Preferences]\nGeneral\\Locale=fi\nWebUI\\Port=23456\nDownloads\\DiskWriteCacheSize=8192\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('fixtur01'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsAllStrings(["General\\Locale=fi\n", "WebUI\\Port=23456\n", "Downloads\\DiskWriteCacheSize=128\n"], $updated);
    }

    public function testApplyManagedConfigRestoresMissingManagedKeysWithinExistingSections(): void
    {
        $configPath = $this->pmssWriteRelativeFile(
            $this->homeRoot,
            'fixtur01/.config/qBittorrent/qBittorrent.conf',
            "[BitTorrent]\nSession\\DiskIOType=MemoryMappedFiles\n\n[Preferences]\nLocale=en\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('fixtur01'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsAllStrings(["Session\\DiskIOType=Posix\n", "Session\\AsyncIOThreadsCount=4\n", "Downloads\\PreAllocation=true\n"], $updated);
    }

    public function testApplyManagedConfigCreatesMissingManagedSections(): void
    {
        $configPath = $this->pmssWriteRelativeFile(
            $this->homeRoot,
            'fixtur01/.config/qBittorrent/qBittorrent.conf',
            "[Application]\nMemoryWorkingSetLimit=2048\n"
        );

        $this->assertTrue(pmssQbittorrentApplyManagedConfig('fixtur01'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsAllStrings(["[BitTorrent]\n", "Session\\DiskCacheSize=128\n", "[Preferences]\n", "Bittorrent\\MaxConnecs=300\n", "WebUI\\HostHeaderValidation=false\n"], $updated);
    }

    public function testApplyManagedConfigRejectsSymlinkTarget(): void
    {
        $configDir = $this->homeRoot.'/fixtur01/.config/qBittorrent';
        @mkdir($configDir, 0755, true);
        $target = $configDir.'/qBittorrent.conf';
        @symlink($this->homeRoot.'/missing-target', $target);

        $this->assertFalse(pmssQbittorrentApplyManagedConfig('fixtur01'));
    }

    public function testApplyUploadThrottlePreservesFileMode(): void
    {
        $configPath = $this->pmssWriteRelativeFile(
            $this->homeRoot,
            'fixtur01/.config/qBittorrent/qBittorrent.conf',
            "[Preferences]\nConnection\\GlobalUPLimit=900\n"
        );
        chmod($configPath, 0640);

        require_once dirname(__DIR__, 2).'/user/qbittorrentThrottleAccount.php';
        $this->assertTrue(pmssQbittorrentThrottleAccount($this->homeRoot.'/fixtur01', 512, true, [
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
        $configPath = $this->pmssWriteRelativeFile($realHomeRoot, 'fixtur01/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nConnection\\GlobalUPLimit=10\n");
        symlink($realHomeRoot, $linkedHomeRoot);
        $this->pmssTrackHomeRoot($linkedHomeRoot);

        $this->assertFalse(pmssQbittorrentApplyUploadThrottle('fixtur01', 512));
        $this->assertSame(
            "[Preferences]\nConnection\\GlobalUPLimit=10\n",
            (string) file_get_contents($configPath)
        );
    }

    public function testMigratedThrottleComparisonDoesNotWriteBeforeShutdown(): void
    {
        // ADR 0182 keys the throttle on the discovered build; pin a 4.4+ build so the host's system build cannot decide.
        $bin = $this->pmssMakeTempDir('pmss-qbt-bin-');
        $this->pmssWriteExecutableFile($bin.'/qbittorrent-nox', "#!/bin/sh\necho 'qBittorrent v4.6.7'\n");
        $this->pmssTrackEnvOverrides($this->pmssPathPrefixedEnvironment($bin));
        $before = "[Meta]\nMigrationVersion=2\n[BitTorrent]\nSession\\GlobalUPSpeedLimit=10\n[Preferences]\nLocale=en\n";
        $path = $this->pmssWriteRelativeFile($this->homeRoot, 'fixtur01/.config/qBittorrent/qBittorrent.conf', $before);
        $this->storeUploadCap(512);
        $this->assertTrue(pmssQbittorrentUploadThrottleNeedsUpdate('fixtur01'));
        $this->assertSame($before, file_get_contents($path));

        // Simulate the daemon saving its old settings on SIGTERM before PMSS writes.
        file_put_contents($path, $before."WebUI\\Port=23456\n");
        $this->assertTrue(pmssQbittorrentApplyUploadThrottle('fixtur01'));
        $updated = file_get_contents($path);
        $this->assertStringContainsAndOmitsStrings(["Session\\GlobalUPSpeedLimit=512\n", "WebUI\\Port=23456\n"], ['Connection\\GlobalUPLimit'], $updated);
        $this->assertFalse(pmssQbittorrentUploadThrottleNeedsUpdate('fixtur01'));
        $this->assertFalse(pmssQbittorrentApplyUploadThrottle('fixtur01'));
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
        $this->storeUploadCap(500);
        $this->assertFalse(pmssQbittorrentUploadThrottleNeedsUpdate('fixtur01'));
        $path = $this->pmssWriteRelativeFile($this->homeRoot, 'fixtur01/.config/qBittorrent/qBittorrent.conf', "[invalid\n");
        $this->assertFalse(pmssQbittorrentUploadThrottleNeedsUpdate('fixtur01'));
        $this->assertFalse(pmssQbittorrentApplyUploadThrottle('fixtur01'));
        unlink($path);
        $target = $this->pmssWriteRelativeFile($this->homeRoot, 'target.conf', "[Preferences]\n");
        symlink($target, $path);
        $this->assertFalse(pmssQbittorrentUploadThrottleNeedsUpdate('fixtur01'));
        $this->assertFalse(pmssQbittorrentApplyUploadThrottle('fixtur01'));
        $this->assertSame("[Preferences]\n", file_get_contents($target));
    }

    /** ADR 0114: an account that never chose a listen address gets IPv4-only listening on the next pass. */
    public function testIpv4ListenAddressFilledWhenUnsetOrEmpty(): void
    {
        foreach (["[BitTorrent]\nSession\\Port=1234\n", "[BitTorrent]\nSession\\InterfaceAddress=\nSession\\Port=1234\n"] as $before) {
            $configPath = $this->pmssWriteRelativeFile($this->homeRoot, 'fixtur01/.config/qBittorrent/qBittorrent.conf', $before);
            $this->assertTrue(pmssQbittorrentApplyManagedConfig('fixtur01'));
            $updated = (string) file_get_contents($configPath);
            $this->assertSame(1, preg_match_all('/^Session\\\\InterfaceAddress=0\.0\.0\.0$/m', $updated));
            $this->assertStringContainsAllStrings(["Session\\Port=1234\n"], $updated);
        }
    }

    /** A customer's own address or interface (a VPN binding) is never changed; 4.1.x would fall back to 127.0.0.1. */
    public function testIpv4ListenFillLeavesCustomerBindingAlone(): void
    {
        $kept = [
            "[BitTorrent]\nSession\\InterfaceAddress=192.0.2.10\n",
            "[BitTorrent]\nSession\\Interface=tun0\nSession\\InterfaceName=tun0\n",
            "[BitTorrent]\nSession\\InterfaceName=wg0\n",
            "[Preferences]\nConnection\\Interface=tun0\n",
        ];
        foreach ($kept as $config) {
            $this->assertSame($config, pmssQbittorrentIpv4ListenFill($config));
        }
    }

    public function testIpv4ListenFillIsIdempotentAndReadsOnlyItsSection(): void
    {
        $once = (string) pmssQbittorrentIpv4ListenFill("[Other]\nSession\\Interface=tun0\n\n[BitTorrent]\nSession\\Port=1\n");
        $this->assertSame(1, preg_match_all('/^Session\\\\InterfaceAddress=0\.0\.0\.0$/m', $once));
        $this->assertSame($once, pmssQbittorrentIpv4ListenFill($once));
    }

    public function testNewAccountTemplateListensOnIpv4Only(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 4).'/etc/seedbox/config/template.qbittorrent.conf');
        $this->assertSame(1, preg_match('/^\[BitTorrent\]$[^\[]*^Session\\\\InterfaceAddress=0\.0\.0\.0$/ms', $template));
    }
}
