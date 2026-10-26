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
        $ops = ['system' => true, 'accountPids' => static function (): array { return []; },
            'restore' => static function () use (&$events): bool { $events[] = 'restore'; return false; },
            'notice' => static function () use (&$events): void { $events[] = 'notice'; },
            'check' => static function (): bool { throw new \RuntimeException('Unstarted socket checked'); }];
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
            'resolve' => static function (): string { return '/must-not-be-executed'; },
            'accountPids' => static function (): array { return []; },
            'restore' => static function () use (&$restores): bool { $restores++; return false; },
            'notice' => static function (): void {},
        ]));
        $this->assertSame(1, $restores);
    }

    public function testCommonStartRestoresLaunchesThenChecksSocket(): void
    {
        $events = [];
        $this->assertTrue(\pmssQbittorrentFrontendStart('/fixture/t',
            static function () use (&$events): void { $events[] = 'launch'; }, [
                'accountPids' => static function (): array { return []; },
                'restore' => static function () use (&$events): bool { $events[] = 'restore'; return true; },
                'check' => static function () use (&$events): bool { $events[] = 'check'; return true; },
            ]));
        $this->assertSame(['restore', 'launch', 'check'], $events);
    }

    public function testSwitchAndFailureRestorePrepareBeforeEachStart(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-switch-');
        $this->pmssEnsureDir($home.'/bin/qbittorrent');
        $events = [];
        $result = \pmssConfigureTorrentClientSwitch($home, ['client' => 'qbittorrent', 'version' => '5.2.4'], [
            'versions' => ['5.2.4' => ['kind' => 'static']],
            'backup' => static function (): array { return ['ok' => true]; },
            'stop' => static function (): bool { return true; },
            'repoint' => static function (): bool { return true; },
            'restore' => static function () use (&$events): bool { $events[] = 'restore'; return true; },
            'start' => static function ($home, $version) use (&$events): bool { $events[] = $version; return $version === 'system'; },
        ]);
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
            'backup' => static function (): array { return ['ok' => true]; },
            'stop' => static function (): bool { return true; },
            'repoint' => static function (): bool { return true; },
            'restore' => static function (): bool { return false; },
            'start' => static function () use (&$starts): bool { $starts++; return true; },
        ]);
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
