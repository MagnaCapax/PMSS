<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TemporaryHomeRootTestCase.php';
require_once __DIR__.'/../../user/qbittorrentStoppedConfig.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentStoppedConfig.php';

final class QbittorrentRestoreTest extends TemporaryHomeRootTestCase
{
    public function testFullMigratedShapeAndParityKeepNewSectionsIntact(): void
    {
        $config = file_get_contents(__DIR__.'/../fixtures/qbittorrentMigrated53.conf');
        $before = \pmssQbittorrentConfigValues($config);
        $root = \pmssQbittorrentRestoreContent($config);
        $this->assertSame($root, \pmssQbittorrentFrontendRestoreContent($config));
        $values = \pmssQbittorrentConfigValues($root);
        $this->assertSame(27, count($before['WebUI']));
        foreach ($before['WebUI'] as $key => $value) $this->assertSame($value, $values['Preferences']['WebUI\\'.$key]);
        $this->assertSame($before['WebUI'], $values['WebUI']);
        $this->assertSame($before['Meta'], $values['Meta']);
        $this->assertSame('/home/qafix081/export', $values['BitTorrent']['Session\\TorrentExportDirectory']);
        $this->assertSame('', $values['BitTorrent']['Session\\FinishedTorrentExportDirectory']);
        $this->assertSame('true', $values['Preferences']['MailNotification\\req_ssl']);
        $this->assertSame('true', $values['Search']['StoreOpenedSearchTabs']);
        $this->assertSame('false', $values['Search']['StoreOpenedSearchTabResults']);
        $this->assertSame($root, \pmssQbittorrentRestoreContent($root));
        foreach (['None', 'STARTTLS', 'SMTPS'] as $encryption) {
            $source = str_replace('SMTPEncryptionType=SMTPS', 'SMTPEncryptionType='.$encryption, $config);
            $restored = \pmssQbittorrentConfigValues(\pmssQbittorrentRestoreContent($source));
            $this->assertSame($encryption === 'SMTPS' ? 'true' : 'false', $restored['Preferences']['MailNotification\\req_ssl']);
        }
    }

    public function testOlderBuildEditsWinAndOnlyMissingWebUiKeysAreFilled(): void
    {
        $config = "[Meta]\nMigrationVersion=8\n[Preferences]\nWebUI\\Port=34567\n[WebUI]\nPort=23456\nRootFolder=/custom\n[Search]\nStoreSearchJobs=true\n";
        foreach (['pmssQbittorrentRestoreContent', 'pmssQbittorrentFrontendRestoreContent'] as $restore) {
            $updated = $restore($config);
            $this->assertSame(str_replace('WebUI\\Port=34567', "WebUI\\Port=34567\nWebUI\\RootFolder=/custom", $config), $updated);
            $this->assertSame($updated, $restore($updated));
        }
    }

    public function testAllDuplicateLegacyValuesAreRestoredWithoutChangingNewSectionBytes(): void
    {
        $web = "[WebUI]\r\nPort=23456\r\nPassword_PBKDF2=owner\r\n";
        $config = "[Meta]\r\nMigrationVersion=12\r\n[Preferences]\r\nWebUI\\Port=8080\r\n"
            ."WebUI\\Port=12345\r\n[Preferences]\r\nWebUI\\Password_PBKDF2=stale\r\n".$web;
        foreach (['pmssQbittorrentRestoreContent', 'pmssQbittorrentFrontendRestoreContent'] as $restore) {
            $updated = $restore($config);
            $this->assertSame(2, substr_count($updated, "WebUI\\Port=23456\r\n"));
            $this->assertStringNotContainsString('stale', $updated);
            $this->assertStringContainsString($web, $updated);
            $this->assertSame($updated, $restore($updated));
        }
    }

    public function testPendingFileWinsWhileEmptyFallbackIsIgnoredOnBothSides(): void
    {
        foreach (['pmssQbittorrentRestore', 'pmssQbittorrentFrontendRestore'] as $restore) {
            foreach (['', "  ; no settings=ignored\n", "[Meta]\nMigrationVersion=12\n[WebUI]\nPort=23456\n"] as $pending) {
                $home = $this->pmssMakeTempDir('pmss-qbt-pending-');
                $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nWebUI\\Port=34567\n");
                $new = $this->pmssWriteFile(dirname($path).'/qBittorrent_new.conf', $pending);
                $this->assertTrue($restore($home, [
                    'write' => static function ($path, $content): bool { return file_put_contents($path, $content) === strlen($content); },
                ] + self::pmssMakeCallbacks(['stopped' => true])));
                $expected = $pending === '' || ltrim($pending)[0] === ';' ? '34567' : '23456';
                $this->assertSame($expected, \pmssQbittorrentWebUiValue(file_get_contents($path), 'Port'));
                if ($expected === '23456') $this->assertFalse(file_exists($new));
            }
        }
    }

    public function testRunningDaemonFailedWriteAndUnsafePendingFileRefuseRestoration(): void
    {
        foreach (['pmssQbittorrentRestore', 'pmssQbittorrentFrontendRestore'] as $restore) {
            $home = $this->pmssMakeTempDir('pmss-qbt-refuse-');
            $config = "[Meta]\nMigrationVersion=12\n[WebUI]\nPort=23456\n";
            $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', $config);
            $this->assertFalse($restore($home, self::pmssMakeCallbacks(['stopped' => false])));
            $this->assertSame($config, file_get_contents($path));
            $this->assertFalse($restore($home, self::pmssMakeCallbacks(['stopped' => true, 'write' => false])));
            $this->assertSame($config, file_get_contents($path));
            $this->pmssCreateSymlinkOrSkip($path, dirname($path).'/qBittorrent_new.conf');
            $this->assertFalse($restore($home, self::pmssMakeCallbacks(['stopped' => true])));
            $this->assertSame($config, file_get_contents($path));
        }
    }
}
