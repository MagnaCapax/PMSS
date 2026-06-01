<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

if (!defined('PMSS_WIREGUARD_NO_ENTRYPOINT')) {
    define('PMSS_WIREGUARD_NO_ENTRYPOINT', true);
}

require_once dirname(__DIR__, 2).'/wireguard.php';

class WireGuardInstallerTest extends TestCase
{
    public function testResolveEndpointSourcePriority(): void
    {
        $cases = [
            [['PMSS_WG_DNS_IP' => '198.51.100.10', 'PMSS_WG_EXTERNAL_IP' => null, 'PMSS_WG_INTERFACE_IP' => null], '198.51.100.10', 'hostname'],
            [['PMSS_WG_DNS_IP' => '10.0.0.1', 'PMSS_WG_EXTERNAL_IP' => '203.0.113.5', 'PMSS_WG_INTERFACE_IP' => '10.0.0.2'], '203.0.113.5', 'external'],
            [['PMSS_WG_DNS_IP' => '10.0.0.2', 'PMSS_WG_EXTERNAL_IP' => '10.0.0.5', 'PMSS_WG_INTERFACE_IP' => '198.51.100.20'], '198.51.100.20', 'interface'],
            [['PMSS_WG_DNS_IP' => '10.0.0.3', 'PMSS_WG_EXTERNAL_IP' => '', 'PMSS_WG_INTERFACE_IP' => '10.0.0.4'], '10.0.0.4', 'interface_private'],
            [['PMSS_WG_DNS_IP' => 'seed.example.com', 'PMSS_WG_EXTERNAL_IP' => '', 'PMSS_WG_INTERFACE_IP' => ''], '', 'unknown'],
        ];

        foreach ($cases as [$env, $expectedIp, $expectedSource]) {
            $this->pmssWithEnv($env, function () use ($expectedIp, $expectedSource): void {
                [$ip, $source] = \wgResolveEndpoint('seed.example.com');
                $this->assertEquals($expectedIp, $ip);
                $this->assertEquals($expectedSource, $source);
            });
        }
    }

    public function testExternalEndpointUrlCandidatesIncludePrimaryAndBackup(): void
    {
        $urls = \wgExternalEndpointUrlCandidates();
        $this->assertTrue(is_array($urls) && count($urls) >= 2, 'Expected at least two external endpoint URL candidates');
        $this->assertTrue(in_array('https://pulsedmedia.com/remote/myip.php', $urls, true), 'Primary PMSS endpoint missing from candidates');
        $this->assertTrue(in_array('https://api.ipify.org', $urls, true), 'Backup endpoint missing from candidates');
    }

    public function testValidatePublicIpRejectsPrivateRanges(): void
    {
        foreach (['10.0.0.1', '127.0.0.1'] as $ip) {
            $this->assertEquals(null, \wgValidatePublicIp($ip));
        }
    }

    public function testValidatePublicKeyAcceptsBase64Key(): void
    {
        // Base64-encoded 32-byte zero buffer.
        $key = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
        $this->assertTrue(\wgValidatePublicKey($key));
    }

    public function testValidatePublicKeyRejectsInvalidKey(): void
    {
        foreach (['not-a-key', 'AAAA'] as $key) {
            $this->assertEquals(false, \wgValidatePublicKey($key));
        }
    }

    public function testWriteConfigOverwritesExisting(): void
    {
        $dir = $this->createTempDir();
        $config = $dir.'/wg0.conf';
        file_put_contents($config, 'existing');

        $homeBase = $this->createTempDir();

	        $this->pmssWithEnv([
	            'PMSS_WG_CONFIG_DIR' => $dir,
	            'PMSS_WG_HOME_BASE'  => $homeBase,
	            'PMSS_WG_USER_LIST'  => 'dummy',
	        ], function (): void {
	            \wireguardWriteConfig('dummy', 12345);
	        });

        $contents = (string) file_get_contents($config);
        $this->assertStringContainsAllStrings(['PrivateKey = dummy', 'ListenPort = 12345'], $contents);
    }

    public function testWriteConfigIncludesPeersFromUserKeys(): void
    {
        $dir      = $this->createTempDir();
        $homeBase = $this->createTempDir();

        @mkdir($homeBase.'/alice', 0755, true);
        @mkdir($homeBase.'/bob', 0755, true);

        // Base64-encoded 32-byte buffers as dummy public keys.
        $aliceKey = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
        $bobKey   = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=';

        file_put_contents($homeBase.'/alice/.wireguard-public-key', $aliceKey."\n");
        file_put_contents($homeBase.'/bob/.wireguard-public-key', $bobKey."\n");

        $this->pmssWithEnv([
            'PMSS_WG_CONFIG_DIR' => $dir,
            'PMSS_WG_HOME_BASE'  => $homeBase,
            'PMSS_WG_USER_LIST'  => 'alice,bob',
        ], function () use ($dir, $aliceKey, $bobKey): void {
            \wireguardWriteConfig('dummy', 12345);
            $config = $dir.'/wg0.conf';
            $contents = $this->pmssAssertFileContainsAllStrings($config, [
                'PrivateKey = dummy',
                'PublicKey = '.$aliceKey,
                'PublicKey = '.$bobKey,
            ]);
            $this->assertMatches('/AllowedIPs = 10\\.90\\.90\\.[0-9]+\\/32/', $contents);
        });
    }

    public function testWriteConfigReturnsFalseWhenConfigPathCannotBeWritten(): void
    {
        $homeBase = $this->createTempDir();

        $this->pmssWithEnv([
            'PMSS_WG_CONFIG_DIR' => $this->createTempDir().'/missing/config',
            'PMSS_WG_HOME_BASE'  => $homeBase,
            'PMSS_WG_USER_LIST'  => 'dummy',
        ], function (): void {
            $this->assertFalse(\wireguardWriteConfig('dummy', 12345));
        });
    }

    public function testEnsureKeysReusesExisting(): void
    {
        $dir = $this->createTempDir();
        file_put_contents($dir.'/server_private.key', "priv\n");
        file_put_contents($dir.'/server_public.key', "pub\n");

        $this->pmssWithEnv([], function () use ($dir): void {
            [$priv, $pub] = \wgEnsureKeys($dir);
            $this->assertEquals('priv', $priv);
            $this->assertEquals('pub', $pub);
        });
    }

    public function testEnsureKeysUsesEnvOverridesWhenGenerating(): void
    {
        $dir = $this->createTempDir();

        $this->pmssWithEnv([
            'PMSS_WG_PRIVATE_KEY' => 'env-priv',
            'PMSS_WG_PUBLIC_KEY'  => 'env-pub',
        ], function () use ($dir): void {
            [$priv, $pub] = \wgEnsureKeys($dir);
            $this->assertEquals('env-priv', $priv);
            $this->assertEquals('env-pub', $pub);
            $this->assertEquals("env-priv\n", (string) file_get_contents($dir.'/server_private.key'));
            $this->assertEquals("env-pub\n", (string) file_get_contents($dir.'/server_public.key'));
        });
    }

    public function testEnsureKeysHandlesGenerationFailure(): void
    {
        $dir = $this->createTempDir();

        $this->pmssWithEnv([
            'PMSS_WG_PRIVATE_KEY' => '',
        ], function () use ($dir): void {
            [$priv, $pub] = \wgEnsureKeys($dir);
            $this->assertEquals('', $priv);
            $this->assertEquals('', $pub);
            $this->assertTrue(!file_exists($dir.'/server_private.key'));
            $this->assertTrue(!file_exists($dir.'/server_public.key'));
        });
    }

    public function testEnsureKeysReturnsEmptyWhenPersistFails(): void
    {
        $dir = $this->createTempDir().'/missing';

        $this->pmssWithEnv([
            'PMSS_WG_PRIVATE_KEY' => 'env-priv',
            'PMSS_WG_PUBLIC_KEY'  => 'env-pub',
        ], function () use ($dir): void {
            [$priv, $pub] = \wgEnsureKeys($dir);
            $this->assertEquals('', $priv);
            $this->assertEquals('', $pub);
            $this->assertFalse(file_exists($dir.'/server_private.key'));
            $this->assertFalse(file_exists($dir.'/server_public.key'));
        });
    }

    public function testBootstrapUserGuideSeedsTemplateWhenPublicKeyAlreadyRegistered(): void
    {
        $homeBase = $this->createTempDir();
        @mkdir($homeBase.'/alice', 0755, true);
        $publicKey = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
        file_put_contents($homeBase.'/alice/.wireguard-public-key', $publicKey."\n");

        $guide = \wgBuildClientGuide('server-pub', 'vpn.example.com', 51820);

        $this->pmssWithEnv([
            'PMSS_WG_HOME_BASE' => $homeBase,
        ], function () use ($guide): void {
            \wgBootstrapUserGuide('alice', $guide);
        });

        $file = $homeBase.'/alice/wireguard.txt';
        $this->assertTrue(file_exists($file), 'registered-key user missing wireguard.txt');
        $this->assertEquals($guide, (string) file_get_contents($file));
        $this->assertEquals($publicKey."\n", (string) file_get_contents($homeBase.'/alice/.wireguard-public-key'));
        $this->assertEquals('600', substr(sprintf('%o', fileperms($file)), -3));
    }

    public function testBootstrapUserGuideKeepsExistingReadyImportGuideForRegisteredKey(): void
    {
        $homeBase = $this->createTempDir();
        @mkdir($homeBase.'/alice', 0755, true);
        file_put_contents($homeBase.'/alice/.wireguard-public-key', "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n");
        file_put_contents($homeBase.'/alice/wireguard.txt', "[Interface]\nPrivateKey = keep-me\n");

        $guide = \wgBuildClientGuide('server-pub', 'vpn.example.com', 51820);

        $this->pmssWithEnv([
            'PMSS_WG_HOME_BASE' => $homeBase,
        ], function () use ($guide): void {
            \wgBootstrapUserGuide('alice', $guide);
        });

        $this->assertEquals("[Interface]\nPrivateKey = keep-me\n", (string) file_get_contents($homeBase.'/alice/wireguard.txt'));
    }

    public function testBuildClientGuideCreatesReadyImportTemplate(): void
    {
        $guide = \wgBuildClientGuide('server-pub', 'vpn.example.com', 51820);

        $this->assertSame('c1501d1931af0c6ec16f58213b837615451961175ada7caefb6679d21ec6ce80', hash('sha256', $guide));
        $this->assertStringContainsAllStrings(["PrivateKey = <client private key>\n", "PublicKey = server-pub\n", "Endpoint = vpn.example.com:51820\n"], $guide);
        $this->assertTrue(strpos($guide, 'WireGuard server ready') === false, 'client guide should be importable without prose');
    }

    public function testBootstrapUserGuideGeneratesKeypairAndGuide(): void
    {
        $homeBase = $this->createTempDir();
        @mkdir($homeBase.'/alice', 0755, true);

        $guide = \wgBuildClientGuide('server-pub', 'vpn.example.com', 51820);
        $publicKey = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

        $this->pmssWithEnv([
            'PMSS_WG_HOME_BASE'           => $homeBase,
            'PMSS_WG_CLIENT_PRIVATE_KEY'  => 'client-private',
            'PMSS_WG_CLIENT_PUBLIC_KEY'   => $publicKey,
        ], function () use ($guide): void {
            \wgBootstrapUserGuide('alice', $guide);
        });

        $guidePath = $homeBase.'/alice/wireguard.txt';
        $keyPath   = $homeBase.'/alice/.wireguard-public-key';

        $guideContents = $this->pmssAssertFileContainsAllStrings($guidePath, ["PrivateKey = client-private\n"], 'wireguard.txt should be created for bootstrap users');
        $this->assertTrue(file_exists($keyPath), '.wireguard-public-key should be created for bootstrap users');
        $this->assertTrue(strpos($guideContents, '<client private key>') === false, 'bootstrap guide should not keep the placeholder');
        $this->assertEquals($publicKey."\n", (string) file_get_contents($keyPath));
        $this->assertEquals('600', substr(sprintf('%o', fileperms($guidePath)), -3));
        $this->assertEquals('600', substr(sprintf('%o', fileperms($keyPath)), -3));
    }

    public function testBootstrapUserGuideSkipsExistingManualGuide(): void
    {
        $homeBase = $this->createTempDir();
        @mkdir($homeBase.'/alice', 0755, true);
        file_put_contents($homeBase.'/alice/wireguard.txt', "[Interface]\nPrivateKey = manual-key\n");

        $guide = \wgBuildClientGuide('server-pub', 'vpn.example.com', 51820);

        $this->pmssWithEnv([
            'PMSS_WG_HOME_BASE'           => $homeBase,
            'PMSS_WG_CLIENT_PRIVATE_KEY'  => 'client-private',
            'PMSS_WG_CLIENT_PUBLIC_KEY'   => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ], function () use ($guide): void {
            \wgBootstrapUserGuide('alice', $guide);
        });

        $this->assertEquals("[Interface]\nPrivateKey = manual-key\n", (string) file_get_contents($homeBase.'/alice/wireguard.txt'));
        $this->assertTrue(!file_exists($homeBase.'/alice/.wireguard-public-key'), 'manual guides should not be replaced automatically');
    }

    public function testBootstrapUserGuideRepairsManagedGuideMissingPublicKeyFile(): void
    {
        $homeBase = $this->createTempDir();
        @mkdir($homeBase.'/alice', 0755, true);

        $guide = \wgApplyPrivateKeyToGuide(
            \wgBuildClientGuide('server-pub', 'vpn.example.com', 51820),
            'client-private'
        );
        file_put_contents($homeBase.'/alice/wireguard.txt', $guide);

        $publicKey = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
        $this->pmssWithEnv([
            'PMSS_WG_HOME_BASE'         => $homeBase,
            'PMSS_WG_CLIENT_PUBLIC_KEY' => $publicKey,
        ], function () use ($guide): void {
            \wgBootstrapUserGuide('alice', $guide);
        });

        $this->assertEquals($guide, (string) file_get_contents($homeBase.'/alice/wireguard.txt'));
        $this->assertEquals($publicKey."\n", (string) file_get_contents($homeBase.'/alice/.wireguard-public-key'));
    }

    public function testBootstrapUserGuideRestoresOriginalGuideWhenPublicKeyWriteFails(): void
    {
        $homeBase = $this->createTempDir();
        @mkdir($homeBase.'/alice', 0755, true);

        $guide = \wgBuildClientGuide('server-pub', 'vpn.example.com', 51820);
        file_put_contents($homeBase.'/alice/wireguard.txt', $guide);
        @mkdir($homeBase.'/alice/.wireguard-public-key', 0755, true);

        $this->pmssWithEnv([
            'PMSS_WG_HOME_BASE'           => $homeBase,
            'PMSS_WG_CLIENT_PRIVATE_KEY'  => 'client-private',
            'PMSS_WG_CLIENT_PUBLIC_KEY'   => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ], function () use ($guide): void {
            \wgBootstrapUserGuide('alice', $guide);
        });

        $this->assertEquals($guide, (string) file_get_contents($homeBase.'/alice/wireguard.txt'));
        $this->assertTrue(is_dir($homeBase.'/alice/.wireguard-public-key'), 'failed public key target should remain untouched');
    }

    public function testApplyAssignedIpToGuideReplacesPlaceholderAddress(): void
    {
        $guide = "[Interface]\nAddress = 10.90.90.X/32\n"
            ."[Peer]\nAllowedIPs = 10.90.90.X/32\n";

        $updated = \wgApplyAssignedIpToGuide($guide, '10.90.90.42');

        $this->assertStringContainsAllStrings(["Address = 10.90.90.42/32\n", "AllowedIPs = 10.90.90.42/32\n"], $updated);
        $this->assertTrue(strpos($updated, '10.90.90.X/32') === false, 'placeholder should be removed from the guide');
    }

    public function testApplyAssignedIpToGuideRefreshesExistingAddress(): void
    {
        $guide = "[Interface]\nAddress = 10.90.90.9/32\n"
            ."[Peer]\nAllowedIPs = 10.90.90.9/32\n";

        $updated = \wgApplyAssignedIpToGuide($guide, '10.90.90.77');

        $this->assertStringContainsAllStrings(["Address = 10.90.90.77/32\n", "AllowedIPs = 10.90.90.77/32\n"], $updated);
        $this->assertTrue(strpos($updated, '10.90.90.9/32') === false, 'stale assigned IP should be replaced');
    }

    public function testApplyAssignedIpToGuideLeavesUnrelatedContentUntouched(): void
    {
        $guide = "WireGuard server ready\nDNS = 1.1.1.1\n";

        $updated = \wgApplyAssignedIpToGuide($guide, '10.90.90.88');

        $this->assertEquals($guide, $updated);
    }

    public function testSyncUserGuideAddressesUpdatesExistingGuide(): void
    {
        $homeBase = $this->createTempDir();
        @mkdir($homeBase.'/alice', 0755, true);

        $guide = "[Interface]\nAddress = 10.90.90.X/32\n"
            ."[Peer]\nAllowedIPs = 10.90.90.X/32\n";
        file_put_contents($homeBase.'/alice/wireguard.txt', $guide);

        $this->pmssWithEnv([
            'PMSS_WG_HOME_BASE' => $homeBase,
        ], function () use ($guide): void {
            \wgSyncUserGuideAddresses([
                ['user' => 'alice', 'key' => 'unused', 'ip' => '10.90.90.42'],
            ], $guide);
        });

        $updated = (string) file_get_contents($homeBase.'/alice/wireguard.txt');
        $this->assertStringContainsAllStrings(["Address = 10.90.90.42/32\n", "AllowedIPs = 10.90.90.42/32\n"], $updated);
    }

    public function testSyncUserGuideAddressesCreatesGuideFromFallback(): void
    {
        $homeBase = $this->createTempDir();
        @mkdir($homeBase.'/alice', 0755, true);

        $guide = "[Interface]\nAddress = 10.90.90.X/32\n"
            ."[Peer]\nAllowedIPs = 10.90.90.X/32\n";

        $this->pmssWithEnv([
            'PMSS_WG_HOME_BASE' => $homeBase,
        ], function () use ($guide): void {
            \wgSyncUserGuideAddresses([
                ['user' => 'alice', 'key' => 'unused', 'ip' => '10.90.90.55'],
            ], $guide);
        });

        $file = $homeBase.'/alice/wireguard.txt';
        $this->pmssAssertFileContainsAllStrings($file, [
            "Address = 10.90.90.55/32\n",
            "AllowedIPs = 10.90.90.55/32\n",
        ], 'wireguard.txt should be created from the fallback guide');
    }

    public function testConfigureKeepsReadmeFlowInline(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/wireguard.php');

        $this->assertStringContainsAllStrings(['template.wireguard.readme', "wgWriteManagedFile(\$configDir.'/README', \$guide, 0644, 'WireGuard README')"], $source);
        $this->assertTrue(strpos($source, 'function wgWrite'.'Readme') === false, 'README helper should stay inlined in pmssWireguardConfigure');
    }

    public function testConfigureKeepsServiceEnableFlowInline(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/wireguard.php');

        $this->assertStringContainsAllStrings(['PMSS_WG_SKIP_SERVICE', 'systemctl enable --now wg-quick@wg0', 'systemd unavailable; skipping wg-quick@wg0 enable'], $source);
        $this->assertTrue(strpos($source, 'function wgEnable'.'Service') === false, 'Service enable helper should stay inlined in pmssWireguardConfigure');
    }

    public function testWireguardUsesDirectLogAndRuntimeRequires(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/wireguard.php');

        $this->assertStringContainsAllStrings(["require_once __DIR__.'/log.php';", "require_once __DIR__.'/update/runtime/commands.php';"], $source);
        $this->assertTrue(strpos($source, "require_once __DIR__.'/update.php';") === false, 'wireguard.php should not pull update.php just to get logmsg()');
        $this->assertTrue(strpos($source, "if (!function_exists('logmsg')) {") === false, 'wireguard.php should rely on require_once instead of logmsg guards');
        $this->assertTrue(strpos($source, "if (!function_exists('runStep')) {") === false, 'wireguard.php should rely on require_once instead of runStep guards');
    }

    private function createTempDir(): string
    {
        return $this->pmssMakeTempDir('pmss-wireguard-tests-', 0700);
    }

}
