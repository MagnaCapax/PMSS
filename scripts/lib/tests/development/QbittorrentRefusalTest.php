<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TemporaryHomeRootTestCase.php';
require_once __DIR__.'/../../user/qbittorrentRestoreAccount.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsInc.php';

/** Explicit retries, watchdog refusals and HTTP responses use isolated account fixtures. */
final class QbittorrentRefusalTest extends TemporaryHomeRootTestCase
{
    public function testRefusalAllowsStoppedRepairButBlocksResolverUntilPortAndHashAreSafe(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-refusal-');
        $this->assertTrue(\pmssQbittorrentFrontendRefuse($home));
        $marker = $home.'/.config/pmss/torrentClients/qbittorrent.refused';
        $this->assertSame(0600, fileperms($marker) & 0777);
        $restores = 0;
        $ops = ['restore' => static function () use (&$restores): bool { $restores++; return true; }];
        for ($cycle = 0; $cycle < 3; $cycle++) {
            $this->assertTrue(\pmssQbittorrentAccountWatchdog($home, false, $ops));
            $this->assertFalse(\pmssTorrentClientResolverLaunch($home, 'qbittorrent', false, ['system' => true]));
        }
        $this->assertSame(3, $restores);
        $this->pmssWriteFile($home.'/.qbittorrentPort', '23456');
        $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', "[Meta]\nMigrationVersion=12\n[WebUI]\nPort=8080\n");
        $release = ['build' => '5.3.0', 'accountPids' => static function (): array { return []; }];
        $this->assertFalse(\pmssQbittorrentFrontendRefusalReady($home, $release));
        $this->pmssWriteFile($path, "[Meta]\nMigrationVersion=12\n[WebUI]\nPort=23456\nPassword_PBKDF2=@ByteArray(c2FsdA==:aGFzaA==)\n");
        $this->assertTrue(\pmssQbittorrentFrontendRefusalReady($home, $release));
        $this->assertFalse(file_exists($marker));
    }
    public function testPanelStartClearsRefusalAndPropagatesTheCommonStartResult(): void
    {
        foreach ([true, false] as $ok) {
            $home = $this->pmssMakeTempDir('pmss-qbt-retry-');
            $this->assertTrue(\pmssQbittorrentFrontendRefuse($home));
            $this->assertSame($ok, \pmssCustomerTorrentClientToggleStart('qbittorrent',
                static function (): void {}, $home, null, [
                    'accountPids' => static function (): array { return []; },
                    'restore' => static function (): bool { return true; },
                    'check' => static function () use ($ok): bool { return $ok; },
                ]));
            $this->assertFalse(file_exists($home.'/.config/pmss/torrentClients/qbittorrent.refused'));
        }
    }

    public function testConfigureExplicitChoiceClearsTheMarkerEvenForAlreadyRunningVersion(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-configure-retry-');
        $this->pmssEnsureDir($home.'/bin/qbittorrent');
        $this->assertTrue(\pmssQbittorrentFrontendRefuse($home));
        $result = \pmssConfigureTorrentClientSwitch($home, ['client' => 'qbittorrent', 'version' => 'system'], [
            'versions' => ['system' => ['kind' => 'system']],
            'runningVersion' => static function (): string { return 'system'; },
            'backup' => static function (): array { throw new \RuntimeException('No-op made backup'); },
        ]);
        $this->assertTrue($result['ok']);
        $this->assertFalse(file_exists($home.'/.config/pmss/torrentClients/qbittorrent.refused'));
    }

    public function testRunningWatchdogChecksEveryCycleWithoutCallingRestore(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-observe-');
        $observations = 0;
        $ops = ['check' => static function ($home, $ops) use (&$observations): bool {
            $observations++;
            return $ops['attempts'] === 1;
        }];
        for ($cycle = 0; $cycle < 3; $cycle++) $this->assertTrue(\pmssQbittorrentAccountWatchdog($home, true, $ops));
        $this->assertSame(3, $observations);
    }

    public function testAlreadyRunningRefusalHasDistinctNoticeAndDoesNoConfigWork(): void
    {
        $message = '';
        $this->assertFalse(\pmssQbittorrentFrontendStart('/fixture/t', static function (): void {}, [
            'accountPids' => static function (): array { return [101]; },
            'restore' => static function (): bool { throw new \RuntimeException('Running daemon config touched'); },
            'notice' => static function ($home, $text) use (&$message): void { $message = $text; },
        ]));
        $this->assertStringContainsString('already running', $message);
        $this->assertStringNotContainsString('could not be restored', $message);
    }

    public function testBusyPanelStartReturnsFalseAndKeepsTheRefusalMarker(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-busy-panel-');
        $this->assertTrue(\pmssQbittorrentFrontendRefuse($home));
        $dir = $home.'/.config/pmss/torrentClients';
        $lock = fopen($dir.'/qbittorrent.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->assertFalse(\pmssCustomerTorrentClientToggleStart('qbittorrent',
                static function (): void { throw new \RuntimeException('Busy panel started'); }, $home));
            $this->assertTrue(is_file($dir.'/qbittorrent.refused'));
            $message = file_get_contents($dir.'/qbittorrent.notice');
            $this->assertStringContainsString('busy with another operation', $message);
            $this->assertStringNotContainsString('could not be restored', $message);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function testAppsEndpointReturnsPlainNoticeWithHttp409(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-http-');
        $this->pmssWriteFile($home.'/www/qbittorrent.php', $this->pmssReadRepoFile('etc/skel/www/qbittorrent.php'));
        // Only the endpoint is exercised in the child; all service and authentication operations are stubs.
        $this->pmssWriteFile($home.'/www/scriptsInc.php', <<<'PHP'
<?php
function pmssFrontendActionRequest() { return 'start'; }
function pmssFrontendPostActionRequired() {}
function pmssQbittorrentFrontendPasswordRequired() { return false; }
function pmssCustomerTorrentClientToggleStart() { return false; }
function pmssFrontendToggleAction($file, $start, $stop) { $start(); echo 'status='.http_response_code(); }
PHP
        );
        foreach (['qBittorrent is busy with another operation; try again shortly.',
            'qBittorrent is already running; stop it before requesting another start.',
            'qBittorrent did not start: its settings file could not be updated. Check that ~/.config/qBittorrent is writable, then start it again.'] as $message) {
            $this->pmssWriteFile($home.'/.config/pmss/torrentClients/qbittorrent.notice', $message."\n");
            $output = []; $rc = 1;
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($home.'/www/qbittorrent.php'), $output, $rc);
            $this->assertSame(0, $rc);
            $this->assertSame([json_encode(['message' => $message]).'status=409'], $output);
        }
    }
}
