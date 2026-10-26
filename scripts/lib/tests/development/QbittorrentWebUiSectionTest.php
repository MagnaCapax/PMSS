<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentWebUiAuth.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentPassword.php';

/** Both deployment trees prefer the build's reader, falling back to file metadata. */
final class QbittorrentWebUiSectionTest extends TestCase
{
    public function testSectionRuleAndReadParity(): void
    {
        foreach (['' => false, 'garbage' => false, '-11' => false, '10' => false, '8' => false,
            '"11' => false, '11.0' => false, '"11"' => true,
            '11' => true, '12' => true, '00011' => true, '999999999999999999999' => true] as $version => $modern) {
            $config = "[Meta]\nMigrationVersion=".$version."\n[Preferences]\nWebUI\\Port=8080\n[WebUI]\nPort=23456\n";
            $expected = $modern ? ['WebUI', ''] : ['Preferences', 'WebUI\\'];
            $this->assertSame($expected, \pmssQbittorrentWebUiSection($config));
            $this->assertSame($expected, \pmssQbittorrentFrontendWebUiSection($config));
            foreach (['pmssQbittorrentWebUiValue', 'pmssQbittorrentFrontendWebUiValue'] as $read)
                $this->assertSame($modern ? '23456' : '8080', $read($config, 'Port'));
        }
        $this->assertSame(['Preferences', 'WebUI\\'], \pmssQbittorrentWebUiSection('[WebUI]'));
    }

    public function testLegacyWriterBytesIncludingNewlinesAndSectionPlacement(): void
    {
        foreach (["\n", "\r\n"] as $nl) foreach (['', '[Meta]'.$nl.'MigrationVersion=10'.$nl] as $meta) {
            $input = $meta.'[Preferences]'.$nl.'WebUI\\Port=8080'.$nl.'Locale=fi'.$nl.$nl.'[Other]'.$nl.'Port=1'.$nl;
            $expected = str_replace('WebUI\\Port=8080', 'WebUI\\Port=23456', $input);
            $this->assertSame($expected, \pmssQbittorrentConfigUpsert($input, 'Preferences', 'WebUI\\Port', '23456'));
            $this->assertSame($expected, \pmssQbittorrentFrontendConfigUpsert($input, 'Preferences', 'WebUI\\Port', '23456'));
        }
        // The endpoint's historical preference writer is exercised with mixed deliveries in PanelHealCompatibilityTest.
    }

    public function testKnownBuildOverridesMigrationMetadataOnBothSides(): void
    {
        $config = "[Meta]\nMigrationVersion=12\n[Preferences]\nWebUI\\Port=23456\n[WebUI]\nPort=34567\n";
        foreach (['4.2.5' => '23456', '4.3.9-lt1.2' => '23456', '4.4.5' => '23456',
            '5.2.4' => '23456', '5.3.0' => '34567', 'unknown' => '34567'] as $build => $port) {
            foreach (['pmssQbittorrentWebUiValue', 'pmssQbittorrentFrontendWebUiValue'] as $read)
                $this->assertSame($port, $read($config, 'Port', $build));
        }
        foreach (['pmssQbittorrentWebUiSection', 'pmssQbittorrentFrontendWebUiSection'] as $section)
            $this->assertSame(['Preferences', 'WebUI\\'], $section("[Meta]\nMigrationVersion=3\n", '5.3.0'));
    }

    public function testModernWritersWriteBothSectionsAndPreserveUnrelatedSettings(): void
    {
        $config = "[Meta]\nMigrationVersion=11\n[WebUI]\nPort=12345\nRootFolder=/custom\n";
        foreach (['pmssQbittorrentConfigUpsert', 'pmssQbittorrentFrontendConfigUpsert'] as $write) {
            $updated = $write($config, 'Preferences', 'WebUI\\Port', '23456');
            $values = \pmssQbittorrentConfigValues($updated);
            $this->assertSame('23456', $values['Preferences']['WebUI\\Port']);
            $this->assertSame('23456', $values['WebUI']['Port']);
            $this->assertSame('/custom', $values['WebUI']['RootFolder']);
            $this->assertSame('11', $values['Meta']['MigrationVersion']);
        }
    }

    public function testMigratedPasswordPresenceUsesOnlyTheAuthoritativeSection(): void
    {
        $path = $this->pmssMakeTempPath('pmss-qbt-section-', '.conf');
        $safe = static function (): bool { return true; };
        $modern = "[Meta]\nMigrationVersion=12\n[Preferences]\nWebUI\\Password_PBKDF2=old\n[WebUI]\n";
        foreach (['' => true, 'Password_PBKDF2=owner' => false,
            'Password_PBKDF2="'.\pmssQbittorrentWebUiAuthSentinel().'"' => true] as $line => $required) {
            $this->pmssWriteFile($path, $modern.$line."\n");
            $this->assertSame($required, \pmssQbittorrentFrontendPasswordRequired($path, $safe, '5.3.0'));
            // The same file's legacy build still reads its own Preferences password.
            $this->assertFalse(\pmssQbittorrentFrontendPasswordRequired($path, $safe, '4.3.9'));
        }
        $forced = \pmssQbittorrentWebUiAuthForce($modern.'Password_PBKDF2=owner');
        $values = \pmssQbittorrentConfigValues($forced);
        foreach (['WebUI' => '', 'Preferences' => 'WebUI\\'] as $section => $prefix) {
            $this->assertSame('owner', $values[$section][$prefix.'Password_PBKDF2']);
            $this->assertSame('true', $values[$section][$prefix.'LocalHostAuth']);
            $this->assertSame('false', $values[$section][$prefix.'AuthSubnetWhitelistEnabled']);
        }
        $this->assertStringNotContainsString(\pmssQbittorrentWebUiAuthSentinel(), $forced);
    }

    public function testModernPasswordWriterUsesOneHashInBothSections(): void
    {
        $root = $this->pmssMakeTempDir('pmss-qbt-password-');
        $path = $this->pmssWriteFile($root.'/qBittorrent.conf', "[Meta]\nMigrationVersion=12\n[WebUI]\nPassword_PBKDF2=old\n");
        $this->assertTrue(\pmssQbittorrentApplyPassword('t', 'separate-webui-password', $path));
        $values = \pmssQbittorrentConfigValues(file_get_contents($path));
        $this->assertSame($values['Preferences']['WebUI\\Password_PBKDF2'], $values['WebUI']['Password_PBKDF2']);
        $this->assertStringNotContainsString('separate-webui-password', file_get_contents($path));
    }
}
