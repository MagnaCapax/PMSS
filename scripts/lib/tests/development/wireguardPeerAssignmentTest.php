<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/wireguard.php';

/** Pin/limit/recovery contracts for scripts/lib/wireguard/peerAssignment.php. */
class WireguardPeerAssignmentTest extends TestCase
{
    private function key(int $index): string
    {
        return base64_encode(hash('sha256', 'peer-'.$index, true));
    }

    private function fixture(array $files, string $users, string $config = ''): array
    {
        $root = $this->pmssMakeTempDir('pmss-wireguard-peers-');
        $home = $root.'/home';
        $dir = $root.'/wireguard';
        mkdir($home);
        mkdir($dir);
        foreach ($files as $user => $contents) {
            mkdir($home.'/'.$user);
            file_put_contents($home.'/'.$user.'/.wireguard-public-key', $contents);
        }
        if ($config !== '') {
            file_put_contents($dir.'/wg0.conf', $config);
        }
        return [$home, $dir, $users];
    }

    private function runFixture(array $fixture): array
    {
        $state = [];
        $this->pmssWithEnv([
            'PMSS_WG_HOME_BASE' => $fixture[0],
            'PMSS_WG_CONFIG_DIR' => $fixture[1],
            'PMSS_WG_USER_LIST' => $fixture[2],
        ], function () use (&$state): void { $state = \wgPeerState(); });
        return $state['assigned'];
    }

    private function peer(string $user, string $key, string $ip): string
    {
        return "\n [Peer] \r\n\t# user={$user} \r\n PublicKey = {$key} \r\n AllowedIPs = {$ip}/32 \r\n";
    }

    public function testMigrationKeepsRenderedConfigByteIdentical(): void
    {
        $first = $this->key(1);
        $keys = [$first];
        for ($i = 2; $i < 2000; $i++) {
            if (\wgDeriveClientIp($this->key($i), []) === \wgDeriveClientIp($first, [])) {
                $keys[] = $this->key($i);
                break;
            }
        }
        $this->assertSame(2, count($keys));
        $keys[] = $this->key(3);
        $used = [];
        $old = [];
        foreach (['fixtur01', 'fixtur02', 'fixtur03'] as $index => $user) {
            $ip = \wgDeriveClientIp($keys[$index], $used);
            $used[$ip] = true;
            $old[] = ['user' => $user, 'key' => $keys[$index], 'ip' => $ip];
        }
        $oldState = ['entriesFound' => true, 'assigned' => $old];
        $this->assertTrue($old[0]['ip'] !== $old[1]['ip']);
        $rendered = \wireguardBuildConfig('server-key', 51820, $oldState);
        $fixture = $this->fixture([
            'fixtur01' => $keys[0]."\n", 'fixtur02' => $keys[1]."\n", 'fixtur03' => $keys[2]."\n",
        ], 'fixtur01,fixtur02,fixtur03', $rendered);
        $assigned = $this->runFixture($fixture);
        $this->assertSame($old, $assigned);
        $this->assertSame($rendered, \wireguardBuildConfig('server-key', 51820, ['entriesFound' => true, 'assigned' => $assigned]));
    }

    public function testNewCollisionCannotMovePinnedHolder(): void
    {
        $key = $this->key(20);
        $ip = \wgDeriveClientIp($key, []);
        $collision = '';
        for ($i = 21; $i < 2000; $i++) {
            if (\wgDeriveClientIp($this->key($i), []) === $ip) { $collision = $this->key($i); break; }
        }
        $this->assertTrue($collision !== '');
        $fixture = $this->fixture(['fixtur01' => $collision."\n", 'fixtur02' => $key."\n"], 'fixtur01,fixtur02', $this->peer('fixtur02', $key, $ip));
        $assigned = $this->runFixture($fixture);
        $this->assertSame($ip, $assigned[1]['ip']);
        $this->assertTrue($assigned[0]['ip'] !== $ip);
    }

    public function testAccountLimitAndExistingPins(): void
    {
        $keys = array_map([$this, 'key'], range(100, 399));
        $fixture = $this->fixture(['fixtur01' => implode("\n", $keys), 'fixtur02' => $this->key(400)], 'fixtur01,fixtur02');
        $this->assertSame(9, count($this->runFixture($fixture)));

        $pins = '';
        foreach (array_slice($keys, 0, 10) as $i => $key) { $pins .= $this->peer('fixtur01', $key, '10.90.90.'.($i + 2)); }
        file_put_contents($fixture[1].'/wg0.conf', $pins);
        $assigned = $this->runFixture($fixture);
        $this->assertSame(11, count($assigned));
        $this->assertSame('fixtur02', $assigned[10]['user']);
    }

    public function testDuplicateKeysAndOwnerPins(): void
    {
        $key = $this->key(500);
        $fixture = $this->fixture(['fixtur01' => $key."\n".$key."\n", 'fixtur02' => $key."\n"], 'fixtur01,fixtur02');
        $this->assertSame(1, count($this->runFixture($fixture)));
        file_put_contents($fixture[1].'/wg0.conf', $this->peer('fixtur02', $key, '10.90.90.9'));
        $assigned = $this->runFixture($fixture);
        $this->assertSame([['user' => 'fixtur02', 'key' => $key, 'ip' => '10.90.90.9']], $assigned);
    }

    public function testFailedReadAndEmptyRosterCarryPins(): void
    {
        $key = $this->key(600);
        $pin = $this->peer('fixtur01', $key, '10.90.90.30');
        $fixture = $this->fixture(['fixtur01' => $key."\n"], 'fixtur01', $pin);
        unlink($fixture[0].'/fixtur01/.wireguard-public-key');
        symlink($fixture[1].'/wg0.conf', $fixture[0].'/fixtur01/.wireguard-public-key');
        $expected = [['user' => 'fixtur01', 'key' => $key, 'ip' => '10.90.90.30']];
        $this->assertSame($expected, $this->runFixture($fixture));
        unlink($fixture[0].'/fixtur01/.wireguard-public-key');
        file_put_contents($fixture[0].'/fixtur01/.wireguard-public-key', str_repeat('x', 65537));
        $this->assertSame($expected, $this->runFixture($fixture));
        $fixture[2] = ',';
        $this->assertSame($expected, $this->runFixture($fixture));
    }

    public function testMovedKeyDoesNotTakeOriginalOwnersAddress(): void
    {
        $shared = $this->key(610);
        $original = $this->key(611);
        $fixture = $this->fixture(
            ['fixtur01' => $original."\n", 'fixtur02' => $shared."\n"],
            'fixtur01,fixtur02',
            $this->peer('fixtur01', $shared, '10.90.90.40').$this->peer('fixtur01', $original, '10.90.90.41')
        );
        $assigned = $this->runFixture($fixture);
        $this->assertSame('10.90.90.41', $assigned[0]['ip']);
        $this->assertTrue($assigned[1]['ip'] !== '10.90.90.40');
    }

    public function testInvalidAndDuplicatePinsAreIgnored(): void
    {
        $key = $this->key(700);
        $other = $this->key(701);
        $config = $this->peer('bad.name', $key, '10.90.90.2')
            .$this->peer('fixtur01', $key, '10.90.90.1')
            .$this->peer('fixtur01', 'invalid', '10.90.90.3')
            .$this->peer('fixtur01', $key, '10.90.90.4')
            .$this->peer('fixtur01', $key, '10.90.90.5')
            .$this->peer('fixtur02', $other, '10.90.90.4');
        $fixture = $this->fixture(['fixtur01' => $key, 'fixtur02' => $other], 'fixtur01,fixtur02', $config);
        $assigned = $this->runFixture($fixture);
        $this->assertSame('10.90.90.4', $assigned[0]['ip']);
        $this->assertTrue($assigned[1]['ip'] !== '10.90.90.4');
    }
}
