<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/install-openclaw';
require_once dirname(__DIR__, 2).'/mediaStackPorts.php';

final class OpenClawInstallerTest extends TestCase
{
    public function testRepeatInstallOnlyReconcilesBrowserWithoutOnboardingOrDownloading(): void
    {
        if (posix_geteuid() === 0) throw new SkipTest('installer refuses root');
        $root = $this->pmssMakeTempDir('pmss-oc-repeat-');
        $home = $root.'/alice';
        mkdir($home.'/stubs', 0700, true);
        $paths = \ocPaths($home);
        mkdir(dirname($paths['node']), 0700, true);
        symlink(PHP_BINARY, $paths['node']);
        mkdir(dirname($paths['entry']), 0700, true);
        file_put_contents($paths['entry'], '<?php file_put_contents(getenv("HOME")."/calls", json_encode(array_slice($argv,1))."\\n", FILE_APPEND); if (($argv[1] ?? "") === "config" && ($argv[2] ?? "") === "get") echo "token";');
        mkdir($paths['state'], 0700);
        \ocEnsureToken($paths);
        $token = file_get_contents($paths['env']);
        file_put_contents($paths['state'].'/openclaw.json', '{"agents":{"defaults":{"model":{"primary":"own/model"}}}}');
        file_put_contents($home.'/.media-stack-port-openclaw', "23000\n");
        file_put_contents($home.'/.lighttpd.conf', "server.port = 24000\n");
        foreach (array('quota' => 'exit 0', 'pkill' => 'exit 0', 'lighttpd' => 'exit 0', 'curl' => 'echo 401',
            'crontab' => 'if [ "$1" = "-l" ]; then echo "no crontab for alice"; exit 1; fi') as $name => $body) {
            file_put_contents($home.'/stubs/'.$name, "#!/bin/sh\n".$body."\n");
            chmod($home.'/stubs/'.$name, 0700);
        }
        $env = getenv();
        $env['HOME'] = $home;
        $env['PATH'] = $home.'/stubs:'.$env['PATH'];
        list($rc, $output) = \ocRun(array(PHP_BINARY, dirname(__DIR__, 4).'/etc/skel/bin/install-openclaw', 'install', '--yes'), $env);
        // The fake gateway exits immediately; all browser reconciliation before it must succeed.
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('Gateway did not stay running', $output);
        $calls = file_get_contents($home.'/calls');
        foreach (array('gateway.controlUi.basePath', 'gateway.publicOrigin', 'gateway.trustedProxies') as $key) {
            $this->assertStringContainsString($key, $calls);
        }
        $this->assertStringNotContainsString('onboard', $calls);
        $this->assertSame($token, file_get_contents($paths['env']));
        $this->assertStringContainsString('own/model', file_get_contents($paths['state'].'/openclaw.json'));
        $this->assertSame(0600, fileperms($paths['state'].'/pmss-provider-pending') & 0777);
        $this->assertSame(\ocProxyFragment('/user-alice/openclaw', 23000), file_get_contents($home.'/.lighttpd/custom.d/openclaw.conf'));
    }

    public function testBrowserFragmentMatchesSharedKeepPrefixRenderer(): void
    {
        require_once dirname(__DIR__, 2).'/lighttpd/proxyFragments.php';
        foreach (array('alice' => 12345, 'bob' => 65500, 'abc123' => 20000) as $user => $port) {
            $base = '/user-'.$user.'/openclaw';
            $this->assertSame(\pmssLighttpdProxyRuleFragment('^'.$base.'($|/)', $port), \ocProxyFragment($base, $port));
        }
    }

    public function testDashboardRewritePreservesBootstrapAndUsesCanonicalWebsocket(): void
    {
        $base = '/user-alice/openclaw';
        $fields = array('bootstrapToken' => 'single+use/secret=', 'bootstrapProfile' => 'owner',
            'gatewayUrl' => 'ws://127.0.0.1:23000'.$base, 'extra' => 'kept');
        $json = json_encode(array('browserUrl' => 'http://127.0.0.1:23000'.$base.'/#'.http_build_query($fields)));
        $url = \ocDashboardUrlRewrite($json, 'https://server.example', $base, 23000);
        $this->assertStringContainsString('https://server.example'.$base.'/#', $url);
        parse_str(parse_url($url, PHP_URL_FRAGMENT), $actual);
        $fields['gatewayUrl'] = 'wss://server.example'.$base;
        $this->assertSame($fields, $actual);
        foreach (array('', '/', $base, $base.'/') as $gatewayPath) {
            $local = $fields;
            $local['gatewayUrl'] = 'ws://127.0.0.1:23000'.$gatewayPath;
            $handoff = json_encode(array('browserUrl' => 'http://127.0.0.1:23000'.$base.'/#'.http_build_query($local)));
            $this->assertSame($url, \ocDashboardUrlRewrite($handoff, 'https://server.example', $base, 23000));
        }
        foreach (array('{}', 'null', 'bad json', json_encode(array('browserUrl' => array())),
            str_replace('127.0.0.1', 'evil.example', $json), str_replace('23000', '23001', $json),
            json_encode(array('browserUrl' => 'http://127.0.0.1:23000'.$base.'/#token=long-lived')),
            json_encode(array('browserUrl' => 'http://127.0.0.1:23000'.$base.'/#bootstrapToken[]=x'))) as $invalid) {
            $threw = false;
            try { \ocDashboardUrlRewrite($invalid, 'https://server.example', $base, 23000); }
            catch (\RuntimeException $e) { $threw = true; }
            $this->assertTrue($threw, 'Malformed handoff accepted');
        }
    }

    public function testProxyFailureRemovesFragmentAndRestartsPanelAgain(): void
    {
        $root = $this->pmssMakeTempDir('pmss-oc-proxy-');
        mkdir($root.'/alice');
        $paths = array('home' => $root.'/alice');
        $calls = 0;
        try {
            \ocProxyApply($paths, 23000, static function () use (&$calls): bool { return ++$calls > 1; });
            $this->assertTrue(false, 'Expected rollback error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('panel recovered', $e->getMessage());
        }
        $this->assertSame(2, $calls);
        $file = $paths['home'].'/.lighttpd/custom.d/openclaw.conf';
        $this->assertFalse(file_exists($file));
        \ocProxyApply($paths, 23000, static function (): bool { return true; });
        $this->assertSame(\ocProxyFragment('/user-alice/openclaw', 23000), file_get_contents($file));
        \ocProxyApply($paths, null, static function (): bool { return true; });
        $this->assertFalse(file_exists($file));
        symlink($root.'/outside', $file);
        $threw = false;
        try { \ocProxyApply($paths, 23000, static function (): bool { return true; }); }
        catch (\RuntimeException $e) { $threw = true; }
        $this->assertTrue($threw);
        $this->assertFalse(file_exists($root.'/outside'));
    }

    public function testLateProviderDecisionTableProtectsUserChoice(): void
    {
        $key = array('key' => 'private', 'endpoint' => 'https://ai.example/v1');
        foreach (array(
            array(false, null, null, 'none'), array(false, $key, null, 'none'),
            array(true, null, null, 'wait'), array(true, null, '', 'wait'),
            array(true, $key, 'own/model', 'remove'), array(true, null, 'own/model', 'remove'),
            array(true, $key, null, 'apply'), array(true, $key, '', 'apply'),
        ) as $row) $this->assertSame($row[3], \ocPendingProviderDecision($row[0], $row[1], $row[2]));
    }

    public function testLateProviderAppliesOnceWithSecretReferenceAndPrivateMarkers(): void
    {
        $home = $this->pmssMakeTempDir('pmss-oc-late-');
        mkdir($home.'/.openclaw', 0700);
        $paths = \ocPaths($home);
        $paths['node'] = PHP_BINARY;
        $paths['entry'] = $home.'/cli.php';
        file_put_contents($paths['entry'], '<?php file_put_contents(getenv("HOME")."/calls", json_encode(array_slice($argv,1))."\\n", FILE_APPEND);');
        file_put_contents($paths['state'].'/openclaw.json', '{}');
        \ocEnsureToken($paths);
        \ocPrivateMarkerWrite($paths['state'].'/pmss-provider-pending', "pending\n");
        $this->assertFalse(\ocPendingProviderApply($paths));
        file_put_contents($home.'/.virsilipasKey', "endpoint=https://ai.example/v1\nkey=private-test-service-key\n");
        chmod($home.'/.virsilipasKey', 0600);
        $prior = getenv('HOME');
        putenv('HOME='.$home);
        try {
            $this->assertTrue(\ocPendingProviderApply($paths));
            $this->assertFalse(\ocPendingProviderApply($paths));
        } finally { $prior === false ? putenv('HOME') : putenv('HOME='.$prior); }
        $calls = file_get_contents($home.'/calls');
        $this->assertSame(2, substr_count($calls, "\n"));
        $this->assertStringContainsString('CUSTOM_API_KEY', $calls);
        $this->assertStringNotContainsString('private-test-service-key', $calls);
        $this->assertSame(0600, fileperms($paths['state'].'/pmss-virsilipas') & 0777);
        $this->assertFalse(file_exists($paths['state'].'/pmss-provider-pending'));
        \ocPrivateMarkerWrite($paths['state'].'/pmss-provider-pending', "pending\n");
        file_put_contents($paths['state'].'/openclaw.json', '{"agents":{"defaults":{"model":{"primary":"own/model"}}}}');
        $this->assertFalse(\ocPendingProviderApply($paths));
        $this->assertFalse(file_exists($paths['state'].'/pmss-provider-pending'));
        $this->assertSame($calls, file_get_contents($home.'/calls'));
        \ocPrivateMarkerWrite($paths['state'].'/pmss-provider-pending', "pending\n");
        file_put_contents($paths['state'].'/openclaw.json', '{"agents":{"defaults":{"model":"own/shorthand"}}}');
        $this->assertFalse(\ocPendingProviderApply($paths));
        $this->assertFalse(file_exists($paths['state'].'/pmss-provider-pending'));
        $this->assertSame($calls, file_get_contents($home.'/calls'));
    }

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
