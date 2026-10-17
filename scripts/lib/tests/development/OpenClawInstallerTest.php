<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/install-openclaw';
require_once dirname(__DIR__, 2).'/mediaStackPorts.php';

final class OpenClawInstallerTest extends TestCase
{
    public function testCmdlineAcceptsEntryAndExactRewrittenTitles(): void
    {
        $entry = '/tmp/oc-entry/openclaw.mjs';
        foreach (array(
            "/tmp/oc-node\0{$entry}\0gateway\0",
            "openclaw\0\0   ",
            "openclaw-gateway\0   \0",
        ) as $cmdline) {
            $this->assertTrue(\ocCmdlineIsGateway($cmdline, $entry));
        }
        foreach (array('', "node\0/other/app.js\0", "openclawx\0", "openclaw-gateway-helper\0") as $cmdline) {
            $this->assertFalse(\ocCmdlineIsGateway($cmdline, $entry));
        }
    }

    public function testQuotaFileHeadroomReadsPlainAndOverQuotaRows(): void
    {
        $header = "Disk quotas for user test (uid 1000):\nFilesystem blocks quota limit grace files quota limit grace\n";
        $this->assertSame(
            array('used' => 54000, 'soft' => 75000, 'hard' => 93750),
            \ocQuotaFileHeadroom($header."/dev/md4 123456 1000000 1250000 54000 75000 93750\n")
        );
        $this->assertSame(
            array('used' => 76000, 'soft' => 75000, 'hard' => 93750),
            \ocQuotaFileHeadroom($header."/dev/md4 123456 1000000 1250000 76000* 75000 93750 6days\n")
        );
        $this->assertSame(
            array('used' => 200, 'soft' => 75000, 'hard' => 93750),
            \ocQuotaFileHeadroom($header."/dev/md4 123456* 1000000 1250000 7days 200 75000 93750\n")
        );
    }

    public function testQuotaFileHeadroomIgnoresUnlimitedAndInvalidOutput(): void
    {
        foreach (array(
            "/dev/md4 123 0 0 200 0 0\n",
            "quota: command unavailable\n",
            "Filesystem blocks quota limit grace files quota limit grace\n",
            "/dev/md4 123 0 0 no-files 75000 93750\n",
            "/dev/md4 123 0 0 999999999999999999999999 75000 93750\n",
        ) as $output) {
            $this->assertSame(null, \ocQuotaFileHeadroom($output));
        }
    }

    public function testCronMergePreservesForeignBytesAndIsIdempotent(): void
    {
        $foreign = "SHELL=/bin/sh\r\n# personal\n* * * * * echo keep\n";
        $merged = \ocCronMerge($foreign, '/tmp/oc-account', true);
        $this->assertSame($foreign, substr($merged, 0, strlen($foreign)));
        $this->assertSame($merged, \ocCronMerge($merged, '/tmp/oc-account', true));
        $this->assertSame($foreign, \ocCronMerge($merged, '/tmp/oc-account', false));
        $this->assertSame(2, substr_count($merged, '# pmss-openclaw'));
    }

    public function testCronMergeRemovesOnlyMarkedLines(): void
    {
        $foreign = "# pmss-openclaw is a note, not a trailing marker\n@daily echo keep # other\n";
        $managed = "@reboot php \$HOME/bin/install-openclaw check # pmss-openclaw\n";
        $this->assertSame($foreign, \ocCronMerge($foreign.$managed, '/tmp/oc-account', false));
    }

    public function testNpmInstallUsesPrivateCacheNotTheAccountNpmDirectory(): void
    {
        $paths = array(
            'home' => '/tmp/oc-account',
            'node' => '/tmp/oc-account/.local/share/node-openclaw/bin/node',
            'npm' => '/tmp/oc-account/.local/share/node-openclaw/lib/node_modules/npm/bin/npm-cli.js',
            'npmCache' => '/tmp/oc-account/.local/share/openclaw-npm-cache',
        );
        $args = \ocNpmInstallArgs($paths);
        $cache = array_search('--cache', $args, true);
        $this->assertTrue($cache !== false);
        $this->assertSame('/tmp/oc-account/.local/share/openclaw-npm-cache', $args[$cache + 1]);
        $this->assertSame(false, in_array('/tmp/oc-account/.npm', $args, true));
        $this->assertSame('openclaw', end($args));
    }

    public function testVirsilipasDecisionRequiresPrivateKeyHttpsAndFreshState(): void
    {
        $home = $this->pmssMakeTempDir('pmss-openclaw-key-');
        $file = $home.'/.virsilipasKey';
        $this->assertSame(null, \ocVirsilipasCredentials($home));
        file_put_contents($file, "endpoint=https://virsilipas.mcx.fi/v1\nkey=\n");
        chmod($file, 0600);
        $this->assertSame(null, \ocVirsilipasCredentials($home));
        file_put_contents($file, "endpoint=http://virsilipas.mcx.fi/v1\nkey=test-private-key\n");
        $this->assertSame(null, \ocVirsilipasCredentials($home));
        file_put_contents($file, "endpoint=https://virsilipas.mcx.fi/v1?x=1\nkey=test-private-key\n");
        $this->assertSame(null, \ocVirsilipasCredentials($home));
        file_put_contents($file, "endpoint=https://virsilipas.mcx.fi/v1\nkey=test-private-key\n");
        $credentials = \ocVirsilipasCredentials($home);
        $this->assertSame('test-private-key', $credentials['key']);
        $state = $home.'/.openclaw';
        $this->assertTrue(\ocUseVirsilipas($state, $credentials));
        $this->assertFalse(\ocUseVirsilipas($state, null));
        mkdir($state, 0700);
        file_put_contents($state.'/openclaw.json', '{"models":{"providers":{"own":{"baseUrl":"https://own.example/v1"}}}}');
        $this->assertFalse(\ocUseVirsilipas($state, $credentials));
        chmod($file, 0644);
        $this->assertSame(null, \ocVirsilipasCredentials($home));
        unlink($file);
        $outside = $this->pmssMakeTempFile('pmss-openclaw-key-outside-');
        $this->pmssCreateSymlinkOrSkip($outside, $file);
        $this->assertSame(null, \ocVirsilipasCredentials($home));
    }

    public function testVirsilipasOnboardingAndWrapperKeepKeyOutOfArgumentsAndFiles(): void
    {
        $home = $this->pmssMakeTempDir('pmss-openclaw-args-');
        $credentials = array('endpoint' => 'https://virsilipas.mcx.fi/v1', 'key' => 'test-private-key');
        $default = \ocOnboardArgs(23000, null);
        $this->assertSame('skip', $default[array_search('--auth-choice', $default, true) + 1]);
        $args = \ocOnboardArgs(23000, $credentials);
        $this->assertSame('custom-api-key', $args[array_search('--auth-choice', $args, true) + 1]);
        $this->assertSame('ref', $args[array_search('--secret-input-mode', $args, true) + 1]);
        $this->assertSame('default', $args[array_search('--custom-model-id', $args, true) + 1]);
        $this->assertSame('openai', $args[array_search('--custom-compatibility', $args, true) + 1]);
        $this->assertFalse(in_array('--custom-api-key', $args, true));
        $this->assertSame(false, strpos(implode(' ', $args), $credentials['key']));
        $wrapper = \ocCliWrapper(array('node' => $home.'/node/bin/node', 'entry' => $home.'/openclaw.mjs'));
        $this->assertSame(false, strpos($wrapper, $credentials['key']));
        $this->assertTrue(strpos($wrapper, 'ocVirsilipasCredentials') !== false);
    }

    public function testCronMergeMigratesLegacyMarkedLine(): void
    {
        $legacy = "@reboot php \$HOME/install-openclaw.php check # pmss-openclaw\n";
        $expected = "@reboot php \$HOME/bin/install-openclaw check # pmss-openclaw\n"
            ."* * * * * php \$HOME/bin/install-openclaw check # pmss-openclaw\n";
        $this->assertSame($expected, \ocCronMerge($legacy, '/tmp/oc-account', true));
    }

    public function testReservedPortRequiresRegularFirstLineInRange(): void
    {
        $root = $this->pmssMakeTempDir('pmss-openclaw-port-');
        $file = $root.'/port';
        foreach (array('1' => 1, "65535\n" => 65535, "25000\nignored\n" => 25000) as $value => $expected) {
            file_put_contents($file, (string) $value);
            $this->assertSame($expected, \ocReservedPort($file));
        }
        foreach (array('', '0', '65536', '18789x', ' 123', '-1', '999999') as $value) {
            file_put_contents($file, $value);
            $this->assertSame(null, \ocReservedPort($file));
        }
        unlink($file);
        $this->assertSame(null, \ocReservedPort($file));
        $outside = $this->pmssMakeTempFile('pmss-openclaw-outside-');
        file_put_contents($outside, "23000\n");
        $this->pmssCreateSymlinkOrSkip($outside, $file);
        $this->assertSame(null, \ocReservedPort($file));
    }

    public function testCheckStateGivesUpOnFifthFailureAndResetsOnHealth(): void
    {
        $state = '';
        for ($failure = 1; $failure <= 4; ++$failure) {
            $state = \ocNextCheckState($state, false, 123);
            $this->assertSame("failed {$failure} 123\n", $state);
        }
        $state = \ocNextCheckState($state, false, 124);
        $this->assertSame("gave_up 124\n", $state);
        $this->assertSame($state, \ocNextCheckState($state, false, 125));
        $this->assertSame("healthy 0 126\n", \ocNextCheckState($state, true, 126));
    }

    public function testGatewayTokenIsPrivateAndReused(): void
    {
        $root = $this->pmssMakeTempDir('pmss-openclaw-token-');
        $paths = array('home' => $root, 'state' => $root, 'env' => $root.'/gateway.env', 'node' => $root.'/node');
        \ocEnsureToken($paths);
        $first = file_get_contents($paths['env']);
        $this->assertSame(0600, fileperms($paths['env']) & 0777);
        $this->assertTrue(preg_match('/^OPENCLAW_GATEWAY_TOKEN=[a-f0-9]{64}\n$/D', $first) === 1);
        \ocEnsureToken($paths);
        $this->assertSame($first, file_get_contents($paths['env']));
    }

    public function testGatewayEnvKeepsRuntimeNpmCacheUnderStateDir(): void
    {
        $root = $this->pmssMakeTempDir('pmss-openclaw-env-');
        $paths = array('env' => $root.'/gateway.env', 'node' => $root.'/node/bin/node', 'state' => $root);
        \ocEnsureToken($paths);
        $env = \ocGatewayEnv($paths);
        $this->assertSame($root.'/npm-cache', $env['npm_config_cache']);
        $this->assertSame($root.'/node/bin', explode(':', $env['PATH'])[0]);
    }

    public function testGatewayReadsRotatedVirsilipasKeyWithoutWritingItToTokenFile(): void
    {
        $home = $this->pmssMakeTempDir('pmss-openclaw-rotation-');
        mkdir($home.'/.openclaw', 0700);
        $paths = array('home' => $home, 'env' => $home.'/.openclaw/gateway.env',
            'node' => $home.'/node/bin/node', 'state' => $home.'/.openclaw');
        \ocEnsureToken($paths);
        file_put_contents($paths['state'].'/pmss-virsilipas', "virsilipas\n");
        $keyFile = $home.'/.virsilipasKey';
        file_put_contents($keyFile, "endpoint=https://virsilipas.mcx.fi/v1\nkey=first-private-key\n");
        chmod($keyFile, 0600);
        $prior = getenv('CUSTOM_API_KEY');
        putenv('CUSTOM_API_KEY');
        try {
            $this->assertSame('first-private-key', \ocGatewayEnv($paths)['CUSTOM_API_KEY']);
            file_put_contents($keyFile, "endpoint=https://virsilipas.mcx.fi/v1\nkey=second-private-key\n");
            $this->assertSame('second-private-key', \ocGatewayEnv($paths)['CUSTOM_API_KEY']);
            $this->assertSame(false, strpos(file_get_contents($paths['env']), 'private-key'));
            unlink($paths['state'].'/pmss-virsilipas');
            $this->assertFalse(isset(\ocGatewayEnv($paths)['CUSTOM_API_KEY']));
        } finally {
            if ($prior === false) {
                putenv('CUSTOM_API_KEY');
            } else {
                putenv('CUSTOM_API_KEY='.$prior);
            }
        }
    }

    public function testPortCatalogAdoptsGatewayPortAndRejectsInvalidContent(): void
    {
        $root = $this->pmssMakeTempDir('pmss-openclaw-catalog-');
        $home = $root.'/test';
        $this->pmssEnsureDir($home.'/.openclaw');
        $definition = \pmssMediaStackPortDefinitions()['openclaw'];
        $this->assertSame('.openclaw/gateway.port', $definition['path']);
        file_put_contents($home.'/.openclaw/gateway.port', "23010\n");
        $this->assertSame(23010, \pmssMediaStackConfiguredPortRead($home, $definition));
        file_put_contents($home.'/.openclaw/gateway.port', "port=23010\n");
        $this->assertSame(null, \pmssMediaStackConfiguredPortRead($home, $definition));
    }
}
