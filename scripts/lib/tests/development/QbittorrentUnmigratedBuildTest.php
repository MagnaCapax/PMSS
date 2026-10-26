<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentWebUiAuth.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentPasswordRequired.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentStart.php';

/** A 5.3 executable migrates Preferences at its next start; it has not moved them yet. */
final class QbittorrentUnmigratedBuildTest extends TestCase
{
    public function testVersionFiveThreeUsesPreferencesUntilMigrationElevenOnBothSides(): void
    {
        foreach (['', 'bad', '8', '10', '11', '12'] as $migration) {
            $config = "[Meta]\nMigrationVersion=".$migration."\n[Preferences]\nWebUI\\Port=23456\n"
                ."WebUI\\Password_PBKDF2=@ByteArray(owner)\n[WebUI]\nPort=34567\nPassword_PBKDF2=stale\n";
            foreach (['pmssQbittorrentWebUiValue', 'pmssQbittorrentFrontendWebUiValue'] as $read)
                $this->assertSame(in_array($migration, ['11', '12'], true) ? '34567' : '23456', $read($config, 'Port', '5.3.0'));
        }
    }

    public function testAuthEnsureAndPasswordPromptKeepTheUnmigratedHashAndPort(): void
    {
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-unmigrated-').'/alice';
        $config = "[Meta]\nMigrationVersion=8\n[Preferences]\nWebUI\\Port=23456\n"
            ."WebUI\\Password_PBKDF2=@ByteArray(owner)\nWebUI\\LocalHostAuth=true\nWebUI\\AuthSubnetWhitelistEnabled=false\n";
        $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', $config);
        $safe = static function (): bool { return true; };
        $this->assertSame($config, \pmssQbittorrentWebUiAuthForce($config, '5.3.0'));
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', $home, ['build' => '5.3.0',
            'enabled' => $safe, 'contextSafe' => $safe, 'pathSafe' => $safe,
            'stateDir' => $home.'/run', 'write' => static function (): void { throw new \RuntimeException('Owner hash replaced'); }]));
        $this->assertFalse(\pmssQbittorrentFrontendPasswordRequired($path, $safe, '5.3.0'));
        $this->assertSame('23456', \pmssQbittorrentWebUiValue($config, 'Port', '5.3.0'));
        $this->assertSame($config, file_get_contents($path));
        $this->assertSame(['BitTorrent', 'Session\\GlobalUPSpeedLimit'], \pmssQbittorrentThrottleKey($config, '5.3.0'));
        foreach (['pmssQbittorrentConfigUpsert', 'pmssQbittorrentFrontendConfigUpsert'] as $write) {
            $updated = $write($config, 'Preferences', 'WebUI\\Port', '34567');
            $this->assertSame(str_replace('WebUI\\Port=23456', 'WebUI\\Port=34567', $config), $updated);
            $this->assertStringNotContainsString('[WebUI]', $updated);
        }
    }

    public function testSocketReadUsesTheUnmigratedConfiguredPort(): void
    {
        $config = "[Meta]\nMigrationVersion=8\n[Preferences]\nWebUI\\Port=8080\n[WebUI]\nPort=34567\n";
        $this->assertTrue(\pmssQbittorrentFrontendSocketWait('/fixture/alice', ['build' => '5.3.0', 'attempts' => 1,
            'read' => static function ($path) use ($config): string { return basename($path) === '.qbittorrentPort' ? '23456' : $config; },
            'pids' => static function (): array { return [101]; },
            'listeners' => static function (): array { return [['address' => '00000000', 'port' => 8080]]; },
            'stop' => static function (): void { throw new \RuntimeException('Configured port was mistaken for fallback'); }]));
    }
}
