<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureSettings.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClients.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsInc.php';

/** Injection boundaries keep every failure path free of real daemon starts or signals. */
final class QbittorrentStartSafetyTest extends TestCase
{
    public function testRestoreFailurePreventsCommonConfigureResolverAndPanelStarts(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-start-');
        $events = [];
        $ops = ['system' => true,
            'check' => static function (): bool { throw new \RuntimeException('Unstarted socket checked'); },
        ] + self::pmssMakeCallbacks(['accountPids' => []])
            + self::pmssMakeCallbacks(['restore' => false, 'notice' => null], $events);
        $launch = static function (): void { throw new \RuntimeException('Failed restore started a daemon'); };
        $this->assertFalse(\pmssQbittorrentFrontendStart($home, $launch, $ops));
        $this->assertFalse(\pmssConfigureTorrentClientStart($home, 'system', $ops));
        $this->assertFalse(\pmssTorrentClientResolverLaunch($home, 'qbittorrent', false, $ops));
        $this->assertFalse(\pmssCustomerTorrentClientToggleStart('qbittorrent', $launch, $home, null, $ops));
        $this->assertSame(array_merge(...array_fill(0, 4, ['restore', 'notice'])), $events);
    }

    public function testSelectedResolverAlsoRefusesBeforeExecutingResolvedBinary(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-selected-');
        $this->pmssEnsureDir($home.'/bin/qbittorrent');
        $this->pmssCreateSymlinkOrSkip('custom', $home.'/bin/qbittorrent/default');
        $restores = 0;
        $this->assertFalse(\pmssTorrentClientResolverLaunch($home, 'qbittorrent', false, [
            'restore' => static function () use (&$restores): bool { $restores++; return false; },
        ] + self::pmssMakeCallbacks(['resolve' => '/must-not-be-executed', 'accountPids' => [], 'notice' => null])));
        $this->assertSame(1, $restores);
    }

    public function testCommonStartRestoresLaunchesThenChecksSocket(): void
    {
        $events = [];
        $launch = self::pmssMakeCallbacks(['launch' => null], $events)['launch'];
        $this->assertTrue(\pmssQbittorrentFrontendStart('/fixture/t',
            $launch, self::pmssMakeCallbacks(['accountPids' => []]) + self::pmssMakeCallbacks(['restore' => true, 'check' => true], $events)));
        $this->assertSame(['restore', 'launch', 'check'], $events);
    }

    public function testSuccessfulStartClearsAnOlderFailureNotice(): void
    {
        // The notice writer only touches files under the customer home root: put the temp home inside it.
        $this->pmssTrackTempRootAsHomeRoot();
        $home = $this->pmssMakeTempDir('pmss-qbt-notice-');
        $notice = $this->pmssWriteFile($home.'/.config/pmss/torrentClients/qbittorrent.notice', "qBittorrent start failed: old.\n");
        $ops = ['build' => '4.5.2'] + self::pmssMakeCallbacks(['accountPids' => [], 'restore' => true, 'throttle' => true]);
        $this->assertFalse(\pmssQbittorrentFrontendStart($home, static function (): void {},
            $ops + self::pmssMakeCallbacks(['check' => false])));
        $this->assertStringContainsString('start failed', file_get_contents($notice));
        $this->assertTrue(\pmssQbittorrentFrontendStart($home, static function (): void {},
            $ops + self::pmssMakeCallbacks(['check' => true])));
        $this->assertSame('', file_get_contents($notice));
    }

    public function testSwitchAndFailureRestorePrepareBeforeEachStart(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-switch-');
        $this->pmssEnsureDir($home.'/bin/qbittorrent');
        $events = [];
        $result = \pmssConfigureTorrentClientSwitch($home, ['client' => 'qbittorrent', 'version' => '5.2.4'], [
            'versions' => ['5.2.4' => ['kind' => 'static']],
            'start' => static function ($home, $version) use (&$events): bool { $events[] = $version; return $version === 'system'; },
        ]
            + self::pmssMakeCallbacks(['backup' => ['ok' => true], 'stop' => true, 'repoint' => true])
            + self::pmssMakeCallbacks(['restore' => true], $events));
        $this->assertFalse($result['ok']);
        $this->assertSame(['restore', '5.2.4', 'restore', 'system'], $events);
    }

    public function testFailedSwitchRestoreNeverCallsEitherStart(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-switch-refuse-');
        $this->pmssEnsureDir($home.'/bin/qbittorrent');
        $starts = 0;
        $result = \pmssConfigureTorrentClientSwitch($home, ['client' => 'qbittorrent', 'version' => '5.2.4'], [
            'versions' => ['5.2.4' => ['kind' => 'static']],
            'start' => static function () use (&$starts): bool { $starts++; return true; },
        ] + self::pmssMakeCallbacks(['backup' => ['ok' => true], 'stop' => true, 'repoint' => true, 'restore' => false]));
        $this->assertFalse($result['ok']);
        $this->assertSame(0, $starts);
        $this->assertStringContainsString('manual recovery', file_get_contents($home.'/.config/pmss/torrentClients/qbittorrent.notice'));
    }

    public function testTrustedPrivilegeBridgePreloadsOnlyOperatorControlledCode(): void
    {
        // No ops seam can emulate the PHP loader's permission boundary: retain this structural check.
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/user/qbittorrentRestoreAccount.php', [
            "'require_once '.var_export(__FILE__, true)", 'posix_setgid(', 'posix_setuid(',
            'pmssQbittorrentAccountWatchdog($argv[2]',
        ]);
    }
}
