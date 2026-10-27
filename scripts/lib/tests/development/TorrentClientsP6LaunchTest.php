<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/torrentClientsResolve.php';

final class TorrentClientsP6LaunchTest extends TestCase
{
    public function testNoSelectionContainsByteIdenticalLegacyCommands(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/cron/checkDelugeInstances.php');
        foreach (['deluged' => '.delugeLog', 'deluge-web' => '.delugeWebLog'] as $daemon => $log) {
            $expected = 'cd ~; '.$daemon.' -l /home/{$thisUser}/'.$log.' -L info';
            $start = strpos($source, '|| (cd ~; '.$daemon);
            $this->assertFalse($start === false);
            $tail = substr($source, $start + 4);
            $this->assertSame($expected, substr($tail, 0, strpos($tail, ')')));
            $this->assertStringContainsString('php ~/www/torrentClientsResolve.php deluge --launch '.$daemon, $source);
        }
        $this->assertStringContainsString('[ -L ~/bin/deluge/default ] && (cd ~; php', $source);
        $this->assertStringContainsString('flock -n 9 || exit 0;', $source);
    }

    public function testSelectedVenvLaunchesBothScriptsWithExistingLogOptions(): void
    {
        [$root, $home, $store] = $this->pmssTorrentClientStoreFixture('pmss-p6-launch-',
            'deluge', '2.1.1', ['bin/deluged' => 'fixture', 'bin/deluge-web' => 'fixture'], 't@localhost');
        symlink($store.'/deluge/2.1.1', $home.'/bin/deluge/default');
        $projection = $root.'/projection.json';
        file_put_contents($projection, json_encode(['versions' => [
            ['client' => 'deluge', 'version' => '2.1.1', 'kind' => 'venv'],
        ]]));
        $commands = [];
        $run = static function (string $command) use (&$commands): int { $commands[] = $command; return 0; };
        $this->assertTrue(\pmssTorrentClientDelugeLaunch($home, $projection, $store, $run));
        $this->assertSame([
            escapeshellarg($store.'/deluge/2.1.1/bin/deluged').' -l '.escapeshellarg($home.'/.delugeLog').' -L info',
            escapeshellarg($store.'/deluge/2.1.1/bin/deluge-web').' -l '.escapeshellarg($home.'/.delugeWebLog').' -L info',
        ], $commands);
        $commands = [];
        $this->assertTrue(\pmssTorrentClientDelugeLaunch($home, $projection, $store, $run, 'deluge-web'));
        $this->assertSame(1, count($commands));
    }

    public function testNoSelectionCannotInvokeResolverLaunch(): void
    {
        $home = $this->pmssMakeTempDir('pmss-p6-no-selection-').'/t@localhost';
        mkdir($home.'/bin/deluge', 0755, true);
        $calls = 0;
        $run = static function () use (&$calls): int { $calls++; return 0; };
        $this->assertFalse(\pmssTorrentClientDelugeLaunch($home, '/missing', '/missing', $run));
        $this->assertSame(0, $calls);
    }
}
