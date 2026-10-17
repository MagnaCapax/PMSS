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
        $ops = [
            'account' => static function (): array { return ['uid' => 1000]; },
            'pids' => static function (): array { return [101, 102, 103]; },
            'namespace' => static function (): string { return 'mnt:[1]'; },
            'exe' => static function (): string { return '/usr/bin/python3.9'; },
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
}
