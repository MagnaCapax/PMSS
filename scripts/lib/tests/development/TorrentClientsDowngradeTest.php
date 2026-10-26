<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureSettings.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsSwitch.php';

/** A refused downgrade must leave the selected client and its state intact. */
final class TorrentClientsDowngradeTest extends TestCase
{
    private function home(string $client): string
    {
        $home = $this->pmssEnsureDir($this->pmssMakeTempDir('pmss-downgrade-').'/t');
        $this->pmssEnsureDir($home.'/bin/'.$client);
        $this->pmssEnsureDir($home.'/.config/pmss/torrentClients');
        return $home;
    }

    public function testMajorComparisonAllowsUnknownFirstSameFamilyAndUpgrade(): void
    {
        foreach (array(array('', '4.0.5'), array('system', '4.0.5'),
            array('4.1.3', '4.0.5'), array('2.1.1', '3.0.0'),
            array('unknown', '1.0.0'), array('4.1.3', 'v3.0.0'),
            array('4.1.3-lt2.0', '4.1.3-lt1.2')) as $pair) {
            $this->assertFalse(\pmssConfigureTorrentClientMajorDowngrade($pair[0], $pair[1]));
        }
        $this->assertTrue(\pmssConfigureTorrentClientMajorDowngrade('5.1.0', '4.9.9'));
        $this->assertTrue(\pmssConfigureTorrentClientMajorDowngrade('02.1', '1.9'));
    }

    public function testQbittorrentDowngradeStopsBeforeAnyMutation(): void
    {
        $this->assertRefusedWithoutCommands('qbittorrent', '5.1.0', '4.9.9');
    }

    public function testCuratedQbittorrentLabelsKeepMajorDowngradeProtection(): void
    {
        foreach (array(array('5.2.4-lt2.0', '4.6.7-lt2.0'),
            array('5.1.4-lt1.2', '4.3.9-lt1.2'), array('5.0.5', '4.4.5-lt2.0'),
            array('5.2.4-lt1.2', '4.5.5'), array('5.2.4-lt2.0', '4.6.7-lt1.2')) as $pair) {
            $this->assertRefusedWithoutCommands('qbittorrent', $pair[0], $pair[1]);
        }
        $this->assertFalse(\pmssConfigureTorrentClientMajorDowngrade('4.6.7-lt2.0', '5.2.4-lt1.2'));
        $this->assertFalse(\pmssConfigureTorrentClientMajorDowngrade('5.2.4-lt2.0', '5.1.4-lt1.2'));
    }

    public function testQbittorrentUpgradeKeepsExistingSwitchSequence(): void
    {
        $home = $this->home('qbittorrent');
        $state = $home.'/.config/pmss/torrentClients/qbittorrent.lastRun';
        $this->pmssWriteFile($state, "4.3.9\n");
        $events = array();
        $record = static function (string $step) use (&$events): bool {
            $events[] = $step;
            return true;
        };
        $ops = array('versions' => array('5.1.0' => array('kind' => 'static')),
            'backup' => static function () use ($record): array {
                return array('ok' => $record('backup'));
            },
            'stop' => static function () use ($record): bool { return $record('stop'); },
            'repoint' => static function () use ($record): bool { return $record('repoint'); },
            'restore' => static function (): bool { return true; },
            'start' => static function () use ($record): bool { return $record('start'); });
        $result = \pmssConfigureTorrentClientSwitch($home,
            array('client' => 'qbittorrent', 'version' => '5.1.0'), $ops);
        $this->assertSame(200, $result['status']);
        $this->assertSame(array('backup', 'stop', 'repoint', 'start'), $events);
        $this->assertSame("5.1.0\n", file_get_contents($state));
    }

    public function testDelugeDowngradeStopsBeforeAnyMutation(): void
    {
        $this->assertRefusedWithoutCommands('deluge', '2.1.1', '1.3.15');
    }

    public function testTransmissionDowngradeStopsBeforeAnyMutation(): void
    {
        $this->assertRefusedWithoutCommands('transmission', '4.1.3', '3.0.0');
    }

    private function assertRefusedWithoutCommands(string $client, string $old, string $new): void
    {
        $home = $this->home($client);
        $state = $home.'/.config/pmss/torrentClients/'.$client.'.lastRun';
        $this->pmssWriteFile($state, $old."\n");
        $events = array();
        $entry = array('kind' => $client === 'deluge' ? 'venv' : 'static');
        if ($client === 'transmission') $entry['rpcUnixSocket'] = true;
        $ops = array('versions' => array($new => $entry),
            'backup' => static function () use (&$events): array { $events[] = 'backup'; return array('ok' => true); },
            'stop' => static function () use (&$events): bool { $events[] = 'stop'; return true; },
            'repoint' => static function () use (&$events): bool { $events[] = 'repoint'; return true; },
            'restore' => static function (): bool { return true; },
            'start' => static function () use (&$events): bool { $events[] = 'start'; return true; });
        $request = array('client' => $client, 'version' => $new);
        $result = $client === 'qbittorrent'
            ? \pmssConfigureTorrentClientSwitch($home, $request, $ops)
            : \pmssConfigureTorrentClientOtherSwitch($home, $request, $ops);
        $this->assertSame(409, $result['status']);
        $this->assertSame(array(), $events);
        $this->assertSame($old."\n", file_get_contents($state));
        $this->assertFalse(file_exists($home.'/bin/'.$client.'/default'));
        $this->assertStringContainsString('switch refused', $result['message']);
    }
}
