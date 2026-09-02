<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsInc.php';
require_once dirname(__DIR__, 2).'/user/scheduledConfigBackup.php';

class ScheduledConfigBackupTest extends TestCase
{
    private $homeRoot = '';
    private $home = '';
    private $configDir = '';

    protected function setUp(): void
    {
        $this->homeRoot = $this->pmssMakeTempDir('pmss-scheduled-config-backup-home-');
        $this->home = $this->pmssEnsureDir($this->homeRoot.'/alice');
        $this->configDir = $this->pmssMakeTempDir('pmss-scheduled-config-backup-config-').'/seedbox/config';
        $logDir = $this->pmssEnsureDir($this->pmssMakeTempDir('pmss-scheduled-config-backup-log-').'/pmss');
        $this->pmssTrackEnvOverrides(['PMSS_HOME_DIR' => $this->homeRoot, 'PMSS_LOG_DIR' => $logDir]);
    }

    private function basePayload(array $overrides = []): array
    {
        return $overrides + [
            'ramMiB' => 512,
            'rtorrentPort' => 5000,
            'quota' => 10,
            'quotaBurst' => 12,
        ];
    }

    private function writeBackupArchive(string $name, int $mtime): string
    {
        $dir = $this->pmssEnsureDir($this->home.'/.pmss-backups', 0700);
        $path = $this->pmssWriteFile($dir.'/'.$name, 'archive');
        touch($path, $mtime);
        return $path;
    }

    public function testBackupToFileWritesValidTarContainingAllowlistPaths(): void
    {
        if (!is_executable('/bin/tar')) {
            throw new SkipTest('/bin/tar is required for scheduled backup archive tests');
        }

        $this->pmssWriteRelativeFile($this->home, '.rtorrent.rc', 'config');
        $this->pmssWriteRelativeFile($this->home, 'session/state.torrent', 'torrent');
        $this->pmssWriteRelativeFile($this->home, '.config/deluge/auth', 'service-secret');
        $this->pmssWriteRelativeFile($this->home, 'data/media.bin', 'media');

        $result = \pmssCustomerBackupFileCreate($this->home);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertTrue($result['bytes'] > 0, 'Scheduled backup must report non-zero bytes');
        $this->assertMatches('#/\.pmss-backups/config-[0-9]{8}-[0-9]{6}(?:-[0-9]{2})?\.tar\.gz\z#', $result['path']);
        $this->assertSame(0700, fileperms($this->home.'/.pmss-backups') & 0777);
        $this->assertSame(0600, fileperms($result['path']) & 0777);

        $members = array();
        $returnCode = 1;
        exec('/bin/tar -tzf '.escapeshellarg($result['path']).' 2>&1', $members, $returnCode);
        $manifest = implode("\n", $members);
        $this->assertSame(0, $returnCode, $manifest);
        $this->assertStringContainsAllStrings(['.rtorrent.rc', 'session/state.torrent', '.config/deluge/auth'], $manifest);
        $this->assertStringNotContainsString('data/media.bin', $manifest);
    }

    public function testRetentionKeepsNewestNAndDeletesOlderArchives(): void
    {
        $this->writeBackupArchive('config-20260101-000001.tar.gz', 100);
        $this->writeBackupArchive('config-20260102-000001.tar.gz', 200);
        $this->writeBackupArchive('config-20260103-000001.tar.gz', 300);
        $this->writeBackupArchive('config-20260104-000001.tar.gz', 400);

        $result = \pmssCustomerBackupRetentionPrune($this->home, 2);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(2, $result['keptCount']);
        $this->assertSame(2, $result['deletedCount']);
        $remaining = array_map('basename', glob($this->home.'/.pmss-backups/config-*.tar.gz') ?: []);
        sort($remaining);
        $this->assertSame(['config-20260103-000001.tar.gz', 'config-20260104-000001.tar.gz'], $remaining);
    }

    public function testCreateThenPruneOrderingLeavesExistingArchivesAfterCreateFailure(): void
    {
        $this->pmssWriteRelativeFile($this->home, 'www/scriptsInc.php', "<?php\n");
        $existing = [
            $this->writeBackupArchive('config-20260101-000001.tar.gz', 100),
            $this->writeBackupArchive('config-20260102-000001.tar.gz', 200),
            $this->writeBackupArchive('config-20260103-000001.tar.gz', 300),
        ];
        $calls = [];
        $runner = static function (string $command, array $context) use (&$calls): array {
            unset($command);
            $calls[] = $context['action'];
            return ['rc' => 1, 'stdout' => '{"ok":false,"message":"simulated backup failure","bytes":0,"path":""}', 'stderr' => ''];
        };

        $result = \pmssScheduledConfigBackupRunUser('alice', $this->basePayload(['scheduledConfigBackup' => true]), [
            'homeRoot' => $this->homeRoot,
            'retention' => 1,
            'runner' => $runner,
            'userLogger' => static function (string $user, string $message): void { unset($user, $message); },
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(['create'], $calls);
        foreach ($existing as $path) {
            $this->assertTrue(is_file($path), 'Existing archive must survive failed create: '.$path);
        }
        $this->assertSame(3, count(glob($this->home.'/.pmss-backups/config-*.tar.gz') ?: []));
    }

    public function testToggleOffIsNoopByDefault(): void
    {
        $store = new \UserConfigStore($this->configDir);
        $this->assertTrue($store->set('alice', $this->basePayload()));
        $runnerCalled = false;

        $summary = \pmssScheduledConfigBackupRun($store, [
            'homeRoot' => $this->homeRoot,
            'runner' => static function () use (&$runnerCalled): array {
                $runnerCalled = true;
                return ['rc' => 0, 'stdout' => '', 'stderr' => ''];
            },
            'logger' => static function (string $message): void { unset($message); },
            'userLogger' => static function (string $user, string $message): void { unset($user, $message); },
        ]);

        $this->assertFalse($runnerCalled, 'Absent scheduledConfigBackup must not invoke the user backup helper');
        $this->assertSame(1, $summary['processed']);
        $this->assertSame(0, $summary['succeeded']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(1, $summary['skipped']);
    }
}
