<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/identity.php';

final class TorrentClientsP6IdentityTest extends TestCase
{
    public function testInterpreterScriptPathDistinguishesVersionsAndDaemons(): void
    {
        $scripts = [101 => '/home/.pmss/clients/deluge/1.3.15/bin/deluged',
            102 => '/home/.pmss/clients/deluge/2.1.1/bin/deluged',
            103 => '/home/.pmss/clients/deluge/2.1.1/bin/deluge-web'];
        $ops = self::pmssMakeCallbacks(['account' => ['uid' => 1000], 'pids' => [101, 102, 103],
            'namespace' => 'mnt:[1]', 'exe' => '/usr/bin/python3.9']) + [
            'read' => static function (string $path) use ($scripts): string {
                preg_match('~/proc/([0-9]+)/([^/]+)$~', $path, $match);
                $pid = (int) ($match[1] ?? 0); $leaf = $match[2] ?? '';
                if ($leaf === 'comm') return "python3\n";
                if ($leaf === 'status') return "Uid:\t1000 1000 1000 1000\n";
                return "python3\0".($scripts[$pid] ?? '')."\0";
            },
            'storeOnly' => true,
        ];
        $this->assertSame([101, 102], \pmssTorrentClientNativeStopPids('t', 'deluge', $ops));
        $ops['scriptPath'] = $scripts[102];
        $this->assertSame([102], \pmssTorrentClientNativeStopPids('t', 'deluge', $ops));
        $ops['processName'] = 'deluge-web';
        unset($ops['scriptPath']);
        $this->assertSame([103], \pmssTorrentClientNativeStopPids('t', 'deluge', $ops));
    }

    public function testDelugeWebNativeStopMatchesSystemAndAccountScripts(): void
    {
        $home = $this->pmssMakeTempDir('pmss-deluge-web-home-');
        $this->pmssEnsureDir($home.'/bin/deluge/custom');
        $scripts = [101 => '/usr/bin/deluge-web', 102 => '/usr/local/bin/deluge-web',
            103 => '/app/deluge-web', 104 => '/home/.pmss/clients/deluge/2.1.1/bin/deluge-web',
            105 => '/usr/bin/deluge-web', 106 => '/usr/bin/deluge-web',
            107 => '/usr/bin/deluge-web', 108 => $home.'/bin/deluge/custom/deluge-web'];
        $exes = array_fill_keys(array_keys($scripts), '/usr/bin/python3.9');
        $exes[105] = '/usr/bin/python3.9 (deleted)';
        $exes[106] = false;
        $exes[107] = '/usr/bin/deluge-web';
        $signals = [];
        $ops = self::pmssMakeCallbacks(['account' => ['uid' => 1000, 'home' => $home, 'email' => 't@localhost'],
            'pids' => array_keys($scripts), 'namespace' => 'mnt:[1]']) + [
            'exe' => static function (int $pid) use ($exes) { return $exes[$pid]; },
            'read' => static function (string $path) use ($scripts) {
                preg_match('~/proc/([0-9]+)/([^/]+)$~', $path, $match);
                $pid = (int) ($match[1] ?? 0);
                if (($match[2] ?? '') === 'comm') return $pid === 107 ? "deluge-web\n" : "python3\n";
                if (($match[2] ?? '') === 'status') return "Uid:\t1000 1000 1000 1000\n";
                return "python3\0".($scripts[$pid] ?? '')."\0";
            },
            'accountRoots' => ['/home/.pmss/clients/deluge/', $home.'/bin/deluge/'],
            'processName' => 'deluge-web',
            'signal' => static function (int $pid, int $signal) use (&$signals): bool {
                $signals[] = [$pid, $signal]; return true;
            },
        ];
        $expected = [101, 102, 104, 105, 107, 108];
        $this->assertSame($expected, \pmssTorrentClientNativeStopPids('t', 'deluge', $ops));
        $this->assertSame(6, \pmssTorrentClientNativeStop('t', 'deluge', 15, $ops));
        $this->assertSame(array_map(static function (int $pid): array { return [$pid, 15]; }, $expected), $signals);
        $ops['storeOnly'] = true;
        $this->assertSame([104], \pmssTorrentClientNativeStopPids('t', 'deluge', $ops));
    }

    public function testDelugeWebStopSitesKeepTheirSignals(): void
    {
        foreach ([
            'lighttpd/delugeWebConf.php' => ['$user', 15],
            'user/deluge.php' => ['$username', 15],
            'user/delugeManagedConfig.php' => ['$username', 15],
            'user/torrentPort.php' => ['$user', 9],
        ] as $file => $case) {
            $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$file);
            $call = "pmssTorrentClientNativeStop({$case[0]}, 'deluge', {$case[1]}, ['processName' => 'deluge-web']);";
            $this->assertSame(1, substr_count($source, $call));
            $this->assertStringNotContainsString('killall', $source);
        }
    }
}
