<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClients.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsOther.php';

/** Observe fixture links with injected native PID and resolver results, never real daemons. */
final class TorrentClientsRunningVersionTest extends TestCase
{
    private function running($home, $client, $ops): string
    {
        return $client === 'qbittorrent' ? \pmssConfigureTorrentClientRunningVersion($home, $ops)
            : \pmssConfigureTorrentClientOtherRunningVersion($home, $client, $ops);
    }

    public function testUnresolvedStoreSelectionNamesTheServerFallbackForEveryClient(): void
    {
        foreach (['qbittorrent', 'rtorrent', 'deluge', 'transmission'] as $client) {
            foreach (['missing', '5.2.4-lt2.0', '0.9.8-r16', '2.2.0', '4.1.3'] as $version) {
                $home = $this->pmssMakeTempDir('pmss-running-unavailable-');
                $this->pmssEnsureDir($home.'/bin/'.$client);
                $this->pmssCreateSymlinkOrSkip('/home/.pmss/clients/'.$client.'/'.$version, $home.'/bin/'.$client.'/default');
                $ops = ['pids' => static function (): array { return [1]; },
                    'resolve' => static function () { return null; }];
                $this->assertSame('system; '.$version.' is not installed on this server', $this->running($home, $client, $ops));
                $ops['pids'] = static function (): array { return []; };
                $this->assertSame('not running', $this->running($home, $client, $ops));
            }
        }
    }

    public function testOtherSystemAndCustomSelectionsRetainTheirLabels(): void
    {
        foreach (['rtorrent', 'deluge', 'transmission'] as $client) {
            $home = $this->pmssMakeTempDir('pmss-running-system-');
            $this->pmssEnsureDir($home.'/bin/'.$client);
            $ops = ['pids' => static function (): array { return [1]; },
                'resolve' => static function () { return null; }];
            $this->assertSame('system', $this->running($home, $client, $ops));
            $this->pmssCreateSymlinkOrSkip('custom', $home.'/bin/'.$client.'/default');
            $this->assertSame('custom', $this->running($home, $client, $ops));
        }
    }
}
