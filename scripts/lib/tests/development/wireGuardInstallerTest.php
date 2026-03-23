<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

if (!defined('PMSS_WIREGUARD_NO_ENTRYPOINT')) {
    define('PMSS_WIREGUARD_NO_ENTRYPOINT', true);
}

require_once dirname(__DIR__, 2).'/wireguard.php';

class WireGuardInstallerTest extends TestCase
{
    /** @var array<string> */
    private array $cleanupPaths = [];

    public function __destruct()
    {
        foreach ($this->cleanupPaths as $path) {
            $this->removePath($path);
        }
    }

    public function testResolveEndpointPrefersDns(): void
    {
        $this->pmssWithEnv([
            'PMSS_WG_DNS_IP'       => '198.51.100.10',
            'PMSS_WG_EXTERNAL_IP'  => null,
            'PMSS_WG_INTERFACE_IP' => null,
        ], function (): void {
            [$ip, $source] = \wgResolveEndpoint('seed.example.com');
            $this->assertEquals('198.51.100.10', $ip);
            $this->assertEquals('hostname', $source);
        });
    }

    public function testExternalEndpointUrlCandidatesIncludePrimaryAndBackup(): void
    {
        $urls = \wgExternalEndpointUrlCandidates();
        $this->assertTrue(is_array($urls) && count($urls) >= 2, 'Expected at least two external endpoint URL candidates');
        $this->assertTrue(in_array('https://pulsedmedia.com/remote/myip.php', $urls, true), 'Primary PMSS endpoint missing from candidates');
        $this->assertTrue(in_array('https://api.ipify.org', $urls, true), 'Backup endpoint missing from candidates');
    }

    public function testResolveEndpointFallsBackToExternalLookup(): void
    {
        $this->pmssWithEnv([
            'PMSS_WG_DNS_IP'       => '10.0.0.1',
            'PMSS_WG_EXTERNAL_IP'  => '203.0.113.5',
            'PMSS_WG_INTERFACE_IP' => '10.0.0.2',
        ], function (): void {
            [$ip, $source] = \wgResolveEndpoint('seed.example.com');
            $this->assertEquals('203.0.113.5', $ip);
            $this->assertEquals('external', $source);
        });
    }

    public function testResolveEndpointUsesInterfaceIp(): void
    {
        $this->pmssWithEnv([
            'PMSS_WG_DNS_IP'       => '10.0.0.2',
            'PMSS_WG_EXTERNAL_IP'  => '10.0.0.5',
            'PMSS_WG_INTERFACE_IP' => '198.51.100.20',
        ], function (): void {
            [$ip, $source] = \wgResolveEndpoint('seed.example.com');
            $this->assertEquals('198.51.100.20', $ip);
            $this->assertEquals('interface', $source);
        });
    }

    public function testResolveEndpointMarksPrivateInterface(): void
    {
        $this->pmssWithEnv([
            'PMSS_WG_DNS_IP'       => '10.0.0.3',
            'PMSS_WG_EXTERNAL_IP'  => '',
            'PMSS_WG_INTERFACE_IP' => '10.0.0.4',
        ], function (): void {
            [$ip, $source] = \wgResolveEndpoint('seed.example.com');
            $this->assertEquals('10.0.0.4', $ip);
            $this->assertEquals('interface_private', $source);
        });
    }

    public function testResolveEndpointFallsBackToHostname(): void
    {
        $this->pmssWithEnv([
            'PMSS_WG_DNS_IP'       => 'seed.example.com',
            'PMSS_WG_EXTERNAL_IP'  => '',
            'PMSS_WG_INTERFACE_IP' => '',
        ], function (): void {
            [$ip, $source] = \wgResolveEndpoint('seed.example.com');
            $this->assertEquals('', $ip);
            $this->assertEquals('unknown', $source);
        });
    }

    public function testValidatePublicIpRejectsPrivateRanges(): void
    {
        $this->assertEquals(null, \wgValidatePublicIp('10.0.0.1'));
        $this->assertEquals(null, \wgValidatePublicIp('127.0.0.1'));
    }

    public function testValidatePublicKeyAcceptsBase64Key(): void
    {
        // Base64-encoded 32-byte zero buffer.
        $key = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
        $this->assertTrue(\wgValidatePublicKey($key));
    }

    public function testValidatePublicKeyRejectsInvalidKey(): void
    {
        $this->assertEquals(false, \wgValidatePublicKey('not-a-key'));
        $this->assertEquals(false, \wgValidatePublicKey('AAAA'));
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
        $this->assertStringContainsString('PrivateKey = dummy', $contents);
        $this->assertStringContainsString('ListenPort = 12345', $contents);
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
            $this->assertTrue(file_exists($config));
            $contents = (string) file_get_contents($config);

            // Base interface section remains.
            $this->assertStringContainsString('PrivateKey = dummy', $contents);

            // Peer sections exist for both keys with per-key AllowedIPs in 10.90.90.0/24.
            $this->assertStringContainsString('PublicKey = '.$aliceKey, $contents);
            $this->assertStringContainsString('PublicKey = '.$bobKey, $contents);
            $this->assertMatches('/AllowedIPs = 10\\.90\\.90\\.[0-9]+\\/32/', $contents);
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

    public function testDistributeToUsersWritesFiles(): void
    {
        $homeBase = $this->createTempDir();
        @mkdir($homeBase.'/alice', 0755, true);
        @mkdir($homeBase.'/bob', 0755, true);

	        $this->pmssWithEnv([
	            'PMSS_WG_HOME_BASE' => $homeBase,
	            'PMSS_WG_USER_LIST' => 'alice,bob',
	        ], function (): void {
	            \wgDistributeToUsers('sample');
	        });

        foreach (['alice', 'bob'] as $user) {
            $file = $homeBase.'/'.$user.'/wireguard.txt';
            $this->assertTrue(file_exists($file), $user.' missing wireguard.txt');
            $this->assertEquals('sample', trim((string) file_get_contents($file)));
            $mode = substr(sprintf('%o', fileperms($file)), -3);
            $this->assertEquals('600', $mode);
        }
    }

    public function testConfigureKeepsReadmeFlowInline(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/wireguard.php');

        $this->assertStringContainsString('template.wireguard.readme', $source);
        $this->assertStringContainsString("file_put_contents(\$configDir.'/README', \$guide);", $source);
        $this->assertTrue(strpos($source, 'function wgWrite'.'Readme') === false, 'README helper should stay inlined in pmssWireguardConfigure');
    }

    public function testConfigureKeepsServiceEnableFlowInline(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/wireguard.php');

        $this->assertStringContainsString('PMSS_WG_SKIP_SERVICE', $source);
        $this->assertStringContainsString('systemctl enable --now wg-quick@wg0', $source);
        $this->assertStringContainsString('systemd unavailable; skipping wg-quick@wg0 enable', $source);
        $this->assertTrue(strpos($source, 'function wgEnable'.'Service') === false, 'Service enable helper should stay inlined in pmssWireguardConfigure');
    }

    public function testWireguardUsesDirectLogAndRuntimeRequires(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/wireguard.php');

        $this->assertStringContainsString("require_once __DIR__.'/log.php';", $source);
        $this->assertStringContainsString("require_once __DIR__.'/update/runtime/commands.php';", $source);
        $this->assertTrue(strpos($source, "require_once __DIR__.'/update.php';") === false, 'wireguard.php should not pull update.php just to get logmsg()');
        $this->assertTrue(strpos($source, "if (!function_exists('logmsg')) {") === false, 'wireguard.php should rely on require_once instead of logmsg guards');
        $this->assertTrue(strpos($source, "if (!function_exists('runStep')) {") === false, 'wireguard.php should rely on require_once instead of runStep guards');
    }

    private function createTempDir(): string
    {
        $dir = $this->pmssMakeNamedTempDir('pmss-wireguard-tests-', 0700);
        $this->cleanupPaths[] = $dir;
        return $dir;
    }

    private function removePath(string $path): void
    {
        $this->cleanup($path);
    }
}
