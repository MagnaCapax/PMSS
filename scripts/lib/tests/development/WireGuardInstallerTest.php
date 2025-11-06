<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

if (!defined('PMSS_WIREGUARD_NO_ENTRYPOINT')) {
    define('PMSS_WIREGUARD_NO_ENTRYPOINT', true);
}

require_once dirname(__DIR__, 2).'/update/apps/wireguard.php';

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
        $this->withEnv([
            'PMSS_WG_DNS_IP'       => '198.51.100.10',
            'PMSS_WG_EXTERNAL_IP'  => null,
            'PMSS_WG_INTERFACE_IP' => null,
        ], function (): void {
            [$ip, $source] = \wgResolveEndpoint('seed.example.com');
            $this->assertEquals('198.51.100.10', $ip);
            $this->assertEquals('hostname', $source);
        });
    }

    public function testResolveEndpointFallsBackToExternalLookup(): void
    {
        $this->withEnv([
            'PMSS_WG_DNS_IP'       => '10.0.0.1',
            'PMSS_WG_EXTERNAL_IP'  => '203.0.113.5',
            'PMSS_WG_INTERFACE_IP' => null,
        ], function (): void {
            [$ip, $source] = \wgResolveEndpoint('seed.example.com');
            $this->assertEquals('203.0.113.5', $ip);
            $this->assertEquals('external', $source);
        });
    }

    public function testResolveEndpointUsesInterfaceIp(): void
    {
        $this->withEnv([
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
        $this->withEnv([
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
        $this->withEnv([
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

    public function testWriteConfigSkipsOverwrite(): void
    {
        $dir = $this->createTempDir();
        $config = $dir.'/wg0.conf';
        file_put_contents($config, 'existing');

        $this->withEnv(['PMSS_WG_CONFIG_DIR' => $dir], function () use ($config): void {
            \wgWriteConfig('dummy', 12345);
        });

        $this->assertEquals('existing', (string) file_get_contents($config));
    }

    public function testEnsureKeysReusesExisting(): void
    {
        $dir = $this->createTempDir();
        file_put_contents($dir.'/server_private.key', "priv\n");
        file_put_contents($dir.'/server_public.key', "pub\n");

        $this->withEnv([], function () use ($dir): void {
            [$priv, $pub] = \wgEnsureKeys($dir);
            $this->assertEquals('priv', $priv);
            $this->assertEquals('pub', $pub);
        });
    }

    public function testEnsureKeysHandlesGenerationFailure(): void
    {
        $dir = $this->createTempDir();

        $this->withEnv([
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

        $this->withEnv([
            'PMSS_WG_HOME_BASE' => $homeBase,
            'PMSS_WG_USER_LIST' => 'alice,bob',
        ], function () use ($homeBase): void {
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

    /**
     * Apply temporary environment variable overrides for the duration of a callback.
     *
     * @param array<string,?string> $variables
     */
    private function withEnv(array $variables, callable $callback): void
    {
        $previous = [];
        foreach ($variables as $name => $value) {
            $previous[$name] = getenv($name);
            if ($value === null) {
                putenv($name);
            } else {
                putenv($name.'='.$value);
            }
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === false) {
                    putenv($name);
                } else {
                    putenv($name.'='.$value);
                }
            }
        }
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir().'/pmss-wireguard-tests-'.uniqid('', true);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $this->cleanupPaths[] = $dir;
        return $dir;
    }

    private function removePath(string $path): void
    {
        if (is_dir($path)) {
            $items = scandir($path) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $this->removePath($path.'/'.$item);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}
