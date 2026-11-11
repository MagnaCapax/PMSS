<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/wireguard.php';

/**
 * Lock scripts/lib/wireguard/state.php and scripts/lib/wireguard/peers.php through their facade.
 * Covers scripts/lib/wireguard/peerConfig.php and scripts/lib/wireguard/peerAssignment.php too.
 */
class WireguardPeerStateCharacterizationTest extends TestCase
{
    public function testPeerBlocksRetainPermissiveParsingAndLastFieldPrecedence(): void
    {
        $this->assertSame([
            ['user' => 'fixtur01', 'key' => 'last-key', 'ip' => '10.90.90.9'],
            ['user' => '', 'key' => 'unowned', 'ip' => ''],
            ['user' => 'fixtur02', 'key' => '', 'ip' => ''],
        ], \wgConfigPeerBlocksFromLines([
            '# user=fixtur01', 'PublicKey = first-key',
            'publickey = last-key', 'AllowedIPs = 10.90.90.8/32',
            'allowedips = 10.90.90.9/24, 10.90.90.10/32',
            '[pEeR] trailing text', 'PublicKey = unowned',
            '[Peer]', '[Peer]', '# user=fixtur02',
        ]));
    }

    public function testPeerBlocksKeepZeroValuesAndIgnoreEmptyBlocks(): void
    {
        foreach ([[], ['[Peer]'], ['[Peer]', ' ', '# ignored', '[Peer]']] as $lines) {
            $this->assertSame([], \wgConfigPeerBlocksFromLines($lines));
        }
        $this->assertSame([['user' => '0', 'key' => '0', 'ip' => '0']],
            \wgConfigPeerBlocksFromLines(['# user=0', 'PublicKey = 0', 'AllowedIPs = 0/32']));
    }

    public function testConfiguredValuesKeepDistinctMatchingAndSortingRules(): void
    {
        $path = $this->pmssMakeTempDir('pmss-wg-state-').'/wg0.conf';
        $first = base64_encode(str_repeat("\0", 32));
        $second = base64_encode(str_repeat("\1", 32));
        $this->pmssWriteFile($path, implode("\r\n", [
            '# user=fixtur10', '# user=fixtur02', '# user=fixtur10',
            '# USER=fixtur03', '# user=bad.name', '# user=toolongname',
            'PublicKey = '.$second, 'PublicKey = '.$first, 'PublicKey = '.$second,
            'publickey = '.base64_encode(str_repeat("\2", 32)),
            'PublicKey = invalid', 'PublicKey = '.$first.' # ignored', '',
        ]));
        $this->assertSame(['status' => 'ok', 'users' => ['fixtur02', 'fixtur10']],
            \pmssWireguardPeerUsersFromConfig($path));
        $this->assertSame(['status' => 'ok', 'keys' => [$first, $second]],
            \pmssWireguardPeerPublicKeysFromConfig($path));
    }

    public function testConfigReadRefusalsAndEmptyFilesKeepTheirResultShapes(): void
    {
        $root = $this->pmssMakeTempDir('pmss-wg-config-');
        $this->pmssWriteFile($root.'/empty', '');
        $this->pmssWriteFile($root.'/target', '# user=fixtur01');
        symlink($root.'/target', $root.'/link');
        foreach ([[$root.'/missing', 'missing'], [$root, 'not_regular'],
            [$root.'/link', 'not_regular'], [$root.'/empty', 'ok']] as [$path, $status]) {
            $this->assertSame(['status' => $status, 'users' => []],
                \pmssWireguardPeerUsersFromConfig($path));
            $this->assertSame(['status' => $status, 'keys' => []],
                \pmssWireguardPeerPublicKeysFromConfig($path));
        }
    }

    public function testPeerRenderingPreservesFallbacksOrderAndFinalWhitespace(): void
    {
        $this->assertSame("# No WireGuard peers configured; place public key(s) in ~/.wireguard-public-key on each user account.\n",
            \wgBuildPeersConfig(['entriesFound' => false, 'assigned' => [['ignored']]]));
        $this->assertSame("# No valid WireGuard peers configured; all provided keys were invalid.\n",
            \wgBuildPeersConfig(['entriesFound' => true, 'assigned' => []]));
        $this->assertSame("# Peers managed by PMSS – do not edit by hand.\n"
            ."[Peer]\n# user=fixtur02\nPublicKey = second\nAllowedIPs = 10.90.90.3/32\n\n"
            ."[Peer]\n# user=fixtur01\nPublicKey = first\nAllowedIPs = 10.90.90.2/32\n",
            \wgBuildPeersConfig(['entriesFound' => true, 'assigned' => [
                ['user' => 'fixtur02', 'key' => 'second', 'ip' => '10.90.90.3'],
                ['user' => 'fixtur01', 'key' => 'first', 'ip' => '10.90.90.2'],
            ]]));
    }

    public function testPublicKeyValidationKeepsItsExactExistingBoundaries(): void
    {
        $key = base64_encode(str_repeat("\0", 32));
        foreach ([$key, " \t".$key."\r\n", rtrim($key, '=')] as $valid) {
            $this->assertTrue(\wgValidatePublicKey($valid));
        }
        foreach (['', 'AAAA', str_repeat('A', 44), $key.'#', $key."\0".'A'] as $invalid) {
            $this->assertFalse(\wgValidatePublicKey($invalid));
        }
    }

    public function testAddressAllocationRetainsReservationsAndExhaustion(): void
    {
        $first = \wgDeriveClientIp('fixture-key', []);
        $this->assertSame($first, \wgDeriveClientIp('fixture-key', []));
        $this->assertTrue($first !== '');
        $second = \wgDeriveClientIp('fixture-key', [$first => true]);
        $this->assertTrue($second !== '' && $second !== $first);
        $reserved = [];
        for ($octet = 2; $octet <= 254; $octet++) $reserved['10.90.90.'.$octet] = true;
        $this->assertSame('', \wgDeriveClientIp('fixture-key', $reserved));
        $this->assertSame(8, WG_MAX_KEYS_PER_ACCOUNT);
    }

    public function testImplicitRenderingUsesTheSameEmptyState(): void
    {
        $root = $this->pmssMakeTempDir('pmss-wg-empty-');
        $this->pmssWithEnv(['PMSS_WG_CONFIG_DIR' => $root, 'PMSS_WG_USER_LIST' => ','], function (): void {
            $this->assertSame(['entriesFound' => false, 'assigned' => []], \wgPeerState());
            $this->assertSame(\wgBuildPeersConfig(['entriesFound' => false, 'assigned' => []]), \wgBuildPeersConfig());
        });
    }
}
