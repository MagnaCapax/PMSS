<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/install-openclaw';
require_once dirname(__DIR__, 2).'/mediaStackPorts.php';

final class OpenClawInstallerTest extends TestCase
{
    public function testUsageAndAllowlistIncludeUpdate(): void
    {
        $installer = dirname(__DIR__, 4).'/etc/skel/bin/install-openclaw';
        list($rc, $output) = \ocRun(array(PHP_BINARY, $installer));
        $this->assertSame(0, $rc);
        $this->assertStringContainsString(' | update | ', $output);
        // A recognized action reaches the root or HOME guard, never the usage fallback.
        $env = getenv();
        $env['HOME'] = '';
        list($rc, $output) = \ocRun(array(PHP_BINARY, $installer, 'update'), $env);
        $this->assertSame(1, $rc);
        $this->assertStringNotContainsString('Usage:', $output);
        $this->assertStringContainsString(posix_geteuid() === 0 ? 'not root' : 'HOME must', $output);
    }

    public function testUpdateQuotaPreflightNeedsSoftRoomNowAndHardRoomForThePeak(): void
    {
        $this->assertSame(60000, OC_UPDATE_REQUIRED_FILES);
        // Rows: used, soft, hard, expected limit named in the refusal (null = allowed).
        foreach (array(
            array(55000, 100000, 125000, null), array(65000, 100000, 125000, null),
            array(65001, 100000, 125000, 'hard'), array(100000, 100000, 160000, null),
            array(100001, 100000, 160000, 'soft'), array(0, 49999, 0, null),
            array(0, 49999, 49999, 'hard'), array(60000, 50000, 0, 'soft'),
            array(PHP_INT_MAX - 60000, PHP_INT_MAX, PHP_INT_MAX, null),
            array(PHP_INT_MAX - 59999, PHP_INT_MAX, PHP_INT_MAX, 'hard'),
        ) as $row) {
            $files = \ocQuotaFileHeadroom('/dev/md4 123 1000 1250 '.$row[0].' '.$row[1].' '.$row[2]);
            $refused = null;
            try { \ocUpdateQuotaPreflight($files); }
            catch (\RuntimeException $error) {
                $refused = strpos($error->getMessage(), 'hard file limit') !== false ? 'hard' : 'soft';
                $numbers = $refused === 'hard' ? array('60000', (string) $row[0], (string) $row[2]) : array((string) $row[0], (string) $row[1]);
                foreach ($numbers as $number) {
                    $this->assertStringContainsString($number, $error->getMessage());
                }
            }
            $this->assertSame($row[3], $refused, implode(',', $row));
        }
        // Missing/unlimited quota data retains the manual install policy.
        \ocUpdateQuotaPreflight(null);
    }

    public function testUpdateRuntimeSelectionOnlyRemovesDirectOwnedDirectoriesWithoutFollowingLinks(): void
    {
        $home = $this->pmssMakeTempDir('pmss-oc-debris-');
        $paths = \ocPaths($home);
        $lib = $home.'/.local/share/openclaw/lib';
        $this->assertSame(array(), \ocUpdateRuntimeDirs($paths));
        mkdir($lib.'/node_modules/openclaw-update-runtime-nested', 0700, true);
        mkdir($lib.'/openclaw-update-runtime-old');
        mkdir($lib.'/openclaw-update-runtime-second');
        mkdir($lib.'/openclaw-update-runtime');
        mkdir($lib.'/other-openclaw-update-runtime-old');
        $outside = $this->pmssMakeTempDir('pmss-oc-debris-outside-');
        file_put_contents($outside.'/keep', 'kept');
        file_put_contents($lib.'/openclaw-update-runtime-file', 'kept');
        symlink($outside, $lib.'/openclaw-update-runtime-link');
        symlink($outside.'/missing', $lib.'/openclaw-update-runtime-broken');
        symlink($outside, $lib.'/openclaw-update-runtime-old/redirect');
        $expected = array($lib.'/openclaw-update-runtime-old', $lib.'/openclaw-update-runtime-second');
        $actual = \ocUpdateRuntimeDirs($paths);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        foreach ($actual as $dir) \ocRemoveTree($dir);
        $this->assertSame(array(), \ocUpdateRuntimeDirs($paths));
        foreach (array('node_modules/openclaw-update-runtime-nested', 'openclaw-update-runtime',
            'other-openclaw-update-runtime-old', 'openclaw-update-runtime-file') as $kept) {
            $this->assertTrue(file_exists($lib.'/'.$kept));
        }
        $this->assertTrue(is_link($lib.'/openclaw-update-runtime-link'));
        $this->assertTrue(is_link($lib.'/openclaw-update-runtime-broken'));
        $this->assertSame('kept', file_get_contents($outside.'/keep'));
        // A redirected lib or ancestor must not authorize cleanup outside the private prefix.
        rename($lib, $lib.'-saved');
        symlink($lib.'-saved', $lib);
        $this->assertSame(array(), \ocUpdateRuntimeDirs($paths));
        unlink($lib);
        rename($lib.'-saved', $lib);
        rename($home.'/.local', $home.'/.local-saved');
        symlink($home.'/.local-saved', $home.'/.local');
        $this->assertSame(array(), \ocUpdateRuntimeDirs($paths));
    }

    public function testUpdateRefusesMissingEntryBeforeQuotaOrGatewayWork(): void
    {
        $paths = \ocPaths($this->pmssMakeTempDir('pmss-oc-missing-'));
        try {
            \ocUpdate($paths);
            $this->assertTrue(false, 'Missing installation accepted');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('not installed', $error->getMessage());
        }
        $this->assertFalse(file_exists($paths['state']));
    }

    public function testUpdateRecoversAfterNpmAndDoctorFailuresUsingPrivateEnvironment(): void
    {
        if (posix_geteuid() === 0) throw new SkipTest('installer refuses root');
        $home = $this->pmssMakeTempDir('pmss-oc-update-');
        $paths = \ocPaths($home);
        mkdir(dirname($paths['node']), 0700, true);
        symlink(PHP_BINARY, $paths['node']);
        mkdir(dirname($paths['entry']), 0700, true);
        mkdir(dirname($paths['npm']), 0700, true);
        mkdir($paths['state'], 0700);
        mkdir($home.'/stubs', 0700);
        \ocEnsureToken($paths);
        $token = file_get_contents($paths['env']);
        file_put_contents($home.'/.media-stack-port-openclaw', "23000\n");
        file_put_contents($home.'/stubs/quota', "#!/bin/sh\ncat \"\$HOME/quota-output\"\n");
        chmod($home.'/stubs/quota', 0700);
        file_put_contents($paths['entry'], '<?php
$command = $argv[1] ?? "";
file_put_contents(getenv("HOME")."/calls", $command."\\n", FILE_APPEND);
if ($command === "--version") echo "2026.10.5";
if ($command === "config") echo "token";
if ($command === "doctor") {
    $valid = array_slice($argv, 2) === array("--fix", "--yes", "--non-interactive")
        && getenv("OPENCLAW_SUPERVISOR_MODE") === "external"
        && getenv("OPENCLAW_SERVICE_REPAIR_POLICY") === "external"
        && preg_match("/^[a-f0-9]{64}$/D", getenv("OPENCLAW_GATEWAY_TOKEN"));
    file_put_contents(getenv("HOME")."/doctor-env", $valid ? "valid" : "invalid");
    echo "private-doctor-output";
    exit(1);
}');
        file_put_contents($paths['npm'], '<?php
file_put_contents(getenv("HOME")."/calls", "npm\\n", FILE_APPEND);
file_put_contents(getenv("HOME")."/npm-path", explode(":", getenv("PATH"))[0]);
echo getenv("OC_TEST_NPM_ERROR");
exit(getenv("OC_TEST_NPM_ERROR") ? 1 : 0);');
        $env = getenv();
        $env['HOME'] = $home;
        $env['PATH'] = $home.'/stubs:'.$env['PATH'];
        $installer = dirname(__DIR__, 4).'/etc/skel/bin/install-openclaw';
        file_put_contents($home.'/quota-output', '/dev/md4 123 1000 1250 43751 75000 93750');
        list($rc, $output) = \ocRun(array(PHP_BINARY, $installer, 'update'), $env);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('60000 additional files', $output);
        $this->assertFalse(file_exists($home.'/calls'));
        file_put_contents($home.'/quota-output', '/dev/md4 123 1000 1250 25000 75000 93750');
        // The fake gateway exits immediately: verify each failure still reaches a restart attempt.
        foreach (array('ENOENT mid-extract', 'EDQUOT', 'errno -122', '') as $npmError) {
            @unlink($home.'/calls');
            $env['OC_TEST_NPM_ERROR'] = $npmError;
            mkdir($paths['npmCache'], 0700);
            list($rc, $output) = \ocRun(array(PHP_BINARY, $installer, 'update'), $env);
            $this->assertSame(1, $rc);
            $this->assertSame("--version\nnpm\n".($npmError === '' ? "doctor\n" : '')."config\ngateway\n", file_get_contents($home.'/calls'));
            $this->assertStringContainsString($npmError === '' ? 'Doctor failed' : ($npmError === 'ENOENT mid-extract' ? $npmError : 'file-count quota was reached'), $output);
            $this->assertStringContainsString('~/.openclaw/logs', $output);
            $this->assertSame(dirname($paths['node']), file_get_contents($home.'/npm-path'));
            $this->assertFalse(is_dir($paths['npmCache']));
            $this->assertFalse(file_exists($paths['state'].'/pmss-stopped'));
            $this->assertSame($token, file_get_contents($paths['env']));
            $this->assertStringNotContainsString(trim(substr($token, strlen('OPENCLAW_GATEWAY_TOKEN='))), $output);
            $this->assertStringNotContainsString('private-doctor-output', $output);
        }
        $this->assertSame('valid', file_get_contents($home.'/doctor-env'));
        // Update refreshes older wrappers so customers' openclaw update is refused upstream.
        $this->assertStringContainsString("export OPENCLAW_SUPERVISOR_MODE=external\n", file_get_contents($home.'/bin/openclaw'));
        // Dispatch shares the watchdog lock even before update checks begin.
        $lock = \ocActionLock($paths);
        list($rc, $output) = \ocRun(array(PHP_BINARY, $installer, 'update'), $env);
        fclose($lock);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('Another OpenClaw action is running', $output);
    }

    public function testWatchdogRearmsAnOlderWrapperEvenWhileStopped(): void
    {
        if (posix_geteuid() === 0) throw new SkipTest('installer refuses root');
        $home = $this->pmssMakeTempDir('pmss-oc-rearm-');
        $paths = \ocPaths($home);
        mkdir($home.'/bin', 0750);
        mkdir($paths['state'], 0700);
        file_put_contents($paths['state'].'/pmss-stopped', '');
        // No installation: the watchdog leaves ~/bin alone.
        \ocCheck($paths);
        $this->assertFalse(file_exists($home.'/bin/openclaw'));
        mkdir(dirname($paths['entry']), 0700, true);
        file_put_contents($paths['entry'], '');
        file_put_contents($home.'/bin/openclaw', "#!/bin/sh\nexec node openclaw.mjs \"\$@\"\n");
        \ocCheck($paths);
        $wrapper = file_get_contents($home.'/bin/openclaw');
        $this->assertStringContainsString("export OPENCLAW_SUPERVISOR_MODE=external\n", $wrapper);
        $this->assertSame(0750, fileperms($home.'/bin/openclaw') & 0777);
        $this->assertSame(array('openclaw'), array_values(array_diff(scandir($home.'/bin'), array('.', '..'))));
        // A current wrapper is left as it is.
        touch($home.'/bin/openclaw', 1000000000);
        \ocCheck($paths);
        clearstatcache();
        $this->assertSame(1000000000, filemtime($home.'/bin/openclaw'));
    }

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

    public function testActionLockIsNotInheritedByProcessesStartedUnderIt(): void
    {
        // lighttpd and the gateway are started while the lock is held; an inherited lock blocked every later action.
        if (!is_dir('/proc/self/fd')) throw new SkipTest('needs /proc');
        $home = $this->pmssMakeTempDir('pmss-oc-lock-');
        $paths = array('home' => $home);
        $lock = \ocActionLock($paths);
        $this->assertTrue($lock !== null);
        $this->assertSame(null, \ocActionLock($paths));
        list($rc, $fds) = \ocRun(array('sh', '-c', 'ls -l /proc/$$/fd'));
        $this->assertSame(0, $rc);
        $this->assertFalse(strpos($fds, '.openclaw-action.lock') !== false, 'child inherited the action lock: '.$fds);
        fclose($lock);
        $again = \ocActionLock($paths);
        $this->assertTrue($again !== null);
        fclose($again);
    }

    public function testCommandPathPrefersPathThenFindsSbinOutsideAccountPath(): void
    {
        // A panel-launched install inherits the account login PATH, which has no /usr/sbin (lighttpd lives there).
        $root = $this->pmssMakeTempDir('pmss-oc-path-');
        file_put_contents($root.'/lighttpd', "#!/bin/sh\n");
        chmod($root.'/lighttpd', 0755);
        $sbin = null;
        foreach ((array) @scandir('/usr/sbin') as $name) {
            if ($name !== '.' && $name !== '..' && is_file('/usr/sbin/'.$name) && is_executable('/usr/sbin/'.$name)) { $sbin = $name; break; }
        }
        $path = (string) getenv('PATH');
        putenv('PATH='.$root);
        try {
            $this->assertSame($root.'/lighttpd', \ocCommandPath('lighttpd'));
            $this->assertSame('pmss-oc-absent-binary', \ocCommandPath('pmss-oc-absent-binary'));
            if ($sbin !== null) $this->assertSame('/usr/sbin/'.$sbin, \ocCommandPath($sbin));
        } finally {
            putenv('PATH='.$path);
        }
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
        $providerCall = json_decode(explode("\n", $calls)[0], true);
        $provider = json_decode($providerCall[3], true);
        $this->assertSame(array('text'), $provider['models'][0]['input']);
        $this->assertSame(true, $provider['models'][0]['compat']['supportsUsageInStreaming']);
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

    /** A fake CLI persists config exactly as config set does, without network or services. */
    private function openClawConfigFixture(array $config = array()): array
    {
        $paths = \ocPaths($this->pmssMakeTempDir('pmss-oc-config-'));
        mkdir($paths['state'], 0700);
        $paths['node'] = PHP_BINARY;
        $paths['entry'] = $paths['home'].'/cli.php';
        \ocEnsureToken($paths);
        file_put_contents($paths['home'].'/.virsilipasKey', "endpoint=https://ai.example/v1\nkey=private-test-service-key\n");
        chmod($paths['home'].'/.virsilipasKey', 0600);
        file_put_contents($paths['state'].'/openclaw.json', json_encode($config));
        file_put_contents($paths['entry'], '<?php
$file = __DIR__."/.openclaw/openclaw.json";
file_put_contents(__DIR__."/calls", json_encode(array_slice($argv, 1))."\\n", FILE_APPEND);
$config = json_decode(file_get_contents($file), true);
if (($argv[1] ?? "") === "onboard") {
    if (($argv[2] ?? "") === "--help") { echo implode(" ", ocTestOnboardFlags()); exit; }
    $config["models"]["providers"]["virsilipas"] = array("baseUrl" => "https://ai.example/v1",
        "apiKey" => array("id" => "CUSTOM_API_KEY", "source" => "env", "provider" => "default"),
        "api" => "openai-completions", "models" => array(array("id" => "default", "name" => "onboarded name")));
} elseif (($argv[1] ?? "") === "config" && ($argv[2] ?? "") === "set") {
    $target =& $config;
    foreach (explode(".", $argv[3]) as $key) $target =& $target[$key];
    $target = json_decode($argv[4], true) ?? $argv[4];
    unset($target);
}
if (($argv[1] ?? "") === "config" && ($argv[2] ?? "") === "get") echo "token";
if (($argv[1] ?? "") === "--version") echo "2026.10.5";
file_put_contents($file, json_encode($config));
function ocTestOnboardFlags() { return array("--non-interactive", "--accept-risk", "--skip-daemon", "--skip-channels",
    "--gateway-token-ref-env", "--custom-base-url", "--custom-model-id", "--custom-provider-id", "--custom-compatibility", "--secret-input-mode"); }
');
        return $paths;
    }

    public function testFreshOnboardingAddsMetadataAndPreservesSecretReference(): void
    {
        $paths = $this->openClawConfigFixture();
        // A pinned-node shim and npm no-op exercise the real fresh-install orchestration.
        $paths['node'] = $paths['home'].'/node';
        file_put_contents($paths['node'], '#!'.PHP_BINARY."\n".'<?php
if (($argv[1] ?? "") === "--version") { echo "v'.OC_NODE_VERSION.'"; exit; }
$file = $argv[1]; array_shift($argv); require $file;');
        chmod($paths['node'], 0700);
        $paths['npm'] = $paths['home'].'/npm.php';
        file_put_contents($paths['npm'], '<?php // No network.');
        $this->assertTrue(\ocInstallFresh($paths, \ocVirsilipasCredentials($paths['home']), true, 23000));
        $provider = \ocConfigRead($paths)['models']['providers']['virsilipas'];
        $this->assertSame('onboarded name', $provider['models'][0]['name']);
        $this->assertSame(array('text'), $provider['models'][0]['input']);
        $this->assertSame(true, $provider['models'][0]['compat']['supportsUsageInStreaming']);
        $this->assertSame(array('id' => 'CUSTOM_API_KEY', 'source' => 'env', 'provider' => 'default'), $provider['apiKey']);
        $this->assertStringNotContainsString('private-test-service-key', file_get_contents($paths['home'].'/calls'));
    }

    public function testProviderMigrationIsIdempotentAndProtectsUserProviders(): void
    {
        $provider = array('baseUrl' => 'https://ai.example/v1', 'api' => 'openai-completions',
            'apiKey' => array('source' => 'env', 'provider' => 'default', 'id' => 'CUSTOM_API_KEY'),
            'models' => array(array('id' => 'default', 'name' => 'preserved', 'cost' => array('input' => 0),
                'compat' => array('supportsStore' => false))));
        $paths = $this->openClawConfigFixture(array('models' => array('providers' => array('virsilipas' => $provider, 'mine' => $provider))));
        $this->assertFalse(\ocVirsilipasModelApply($paths, \ocGatewayEnv($paths)));
        \ocPrivateMarkerWrite($paths['state'].'/pmss-virsilipas', "virsilipas\n");
        $this->assertTrue(\ocVirsilipasModelApply($paths, \ocGatewayEnv($paths)));
        $this->assertFalse(\ocVirsilipasModelApply($paths, \ocGatewayEnv($paths)));
        $actual = \ocConfigRead($paths)['models']['providers'];
        $this->assertSame($provider, $actual['mine']);
        $this->assertSame($provider['apiKey'], $actual['virsilipas']['apiKey']);
        $this->assertSame(false, $actual['virsilipas']['models'][0]['compat']['supportsStore']);
        $this->assertSame(array('input' => 0), $actual['virsilipas']['models'][0]['cost']);
        $this->assertSame(1, substr_count(file_get_contents($paths['home'].'/calls'), "\n"));
        foreach (array('endpoint', 'model', 'secret', 'multiple', 'api') as $case) {
            $custom = $provider;
            if ($case === 'endpoint') $custom['baseUrl'] = 'https://own.example/v1';
            if ($case === 'model') $custom['models'][0]['id'] = 'custom';
            if ($case === 'secret') $custom['apiKey'] = 'user-private-key';
            if ($case === 'multiple') $custom['models'][] = array('id' => 'other');
            if ($case === 'api') $custom['api'] = 'openai-responses';
            $config = array('models' => array('providers' => array('virsilipas' => $custom)));
            file_put_contents($paths['state'].'/openclaw.json', json_encode($config));
            $this->assertFalse(\ocVirsilipasModelApply($paths, \ocGatewayEnv($paths)), $case);
            $this->assertSame($config, \ocConfigRead($paths));
        }
        // A pending marker cannot overwrite a provider installed by the user, even with no primary.
        \ocPrivateMarkerWrite($paths['state'].'/pmss-provider-pending', "pending\n");
        $this->assertFalse(\ocPendingProviderApply($paths));
        $this->assertSame($config, \ocConfigRead($paths));
    }

    public function testKokkoPinnedInstallAppendsUserDirsAndUpdatesIdempotently(): void
    {
        $paths = $this->openClawConfigFixture(array('skills' => array('load' => array('extraDirs' => array('/my/skills', '/other/skills')))));
        $downloads = 0;
        $fixture = __DIR__.'/../fixtures/mcxKokko-v0.1.1.tar.gz';
        $download = function (string $url, string $archive) use ($paths, $fixture, &$downloads): bool {
            ++$downloads;
            $this->assertSame('https://github.com/MagnaCapax/mcxKokko/archive/refs/tags/v0.1.1.tar.gz', $url);
            $this->assertSame($paths['home'], dirname($archive, 2));
            $this->assertSame(0700, fileperms(dirname($archive)) & 0777);
            $this->assertSame('pmss-kokko-v0.1.0', readlink($paths['state'].'/pmss-kokko'));
            return copy($fixture, $archive);
        };
        // Only marked older versions can be pruned.
        $old = $paths['state'].'/pmss-kokko-v0.1.0';
        mkdir($old, 0700);
        \ocPrivateMarkerWrite($old.'/.pmss-kokko', "v0.1.0\n".str_repeat('a', 64)."\n");
        symlink(basename($old), $paths['state'].'/pmss-kokko');
        mkdir($paths['state'].'/pmss-kokko-v0.0.1', 0700);
        \ocKokkoApply($paths, false, $download);
        $this->assertSame(1, $downloads);
        $this->assertSame(OC_KOKKO_VERSION, \ocKokkoStatus($paths));
        $live = $paths['state'].'/pmss-kokko';
        $this->assertSame('pmss-kokko-v0.1.1', readlink($live));
        $this->assertTrue(is_file($live.'/skills/pm-ai-credits/SKILL.md'));
        $this->assertSame(posix_geteuid(), fileowner($live.'/skills/pm-ai-credits/SKILL.md'));
        $this->assertSame(array('/my/skills', '/other/skills', $live.'/skills'), \ocConfigRead($paths)['skills']['load']['extraDirs']);
        $this->assertFalse(is_dir($old));
        $this->assertTrue(is_dir($paths['state'].'/pmss-kokko-v0.0.1'));
        $calls = file_get_contents($paths['home'].'/calls');
        $stat = lstat($live);
        \ocKokkoApply($paths, false, $download);
        $this->assertSame(1, $downloads);
        $this->assertSame($calls, file_get_contents($paths['home'].'/calls'));
        $this->assertSame($stat['ino'], lstat($live)['ino']);
        $this->assertSame(array(), glob($paths['home'].'/.openclaw-kokko-*'));
        // Disable only removes our load path; uninstall removes marked payloads too.
        touch($paths['state'].'/pmss-kokko-disabled');
        \ocKokkoApply($paths, false, $download);
        $this->assertSame(array('/my/skills', '/other/skills'), \ocConfigRead($paths)['skills']['load']['extraDirs']);
        $this->assertStringContainsString('disabled', \ocKokkoStatus($paths));
        $this->assertTrue(is_link($live));
        \ocKokkoApply($paths, true, $download);
        $this->assertFalse(is_link($live));
        $this->assertFalse(is_dir($live.'-'.OC_KOKKO_VERSION));
        $this->assertTrue(is_dir($paths['state'].'/pmss-kokko-v0.0.1'));
        $this->assertSame(1, $downloads);
    }

    public function testKokkoDownloadFailuresAndHashMismatchLeaveConfigAndCleanStaging(): void
    {
        foreach (array('failure', 'mismatch', 'exception') as $case) {
            $paths = $this->openClawConfigFixture(array('skills' => array('load' => array('extraDirs' => array('/user/skills')))));
            $before = file_get_contents($paths['state'].'/openclaw.json');
            $old = $paths['state'].'/pmss-kokko-v0.1.0';
            mkdir($old, 0700);
            \ocPrivateMarkerWrite($old.'/.pmss-kokko', "v0.1.0\n".str_repeat('a', 64)."\n");
            symlink(basename($old), $paths['state'].'/pmss-kokko');
            \ocKokkoApply($paths, false, static function (string $url, string $archive) use ($case): bool {
                if ($case === 'exception') throw new \RuntimeException('private-test-service-key');
                file_put_contents($archive, 'not a tar archive');
                return $case !== 'failure';
            });
            $this->assertSame($before, file_get_contents($paths['state'].'/openclaw.json'));
            $this->assertSame(basename($old), readlink($paths['state'].'/pmss-kokko'));
            $this->assertSame(array(), glob($paths['home'].'/.openclaw-kokko-*'));
            $this->assertFalse(file_exists($paths['home'].'/calls'));
        }
    }

    public function testKokkoEmptyExtraDirsOptOutAndForeignPaths(): void
    {
        foreach (array(array(), array('skills' => array('load' => array('extraDirs' => array())))) as $config) {
            $paths = $this->openClawConfigFixture($config);
            $this->assertTrue(\ocKokkoExtraDirs($paths, true));
            $this->assertSame(array($paths['state'].'/pmss-kokko/skills'), \ocConfigRead($paths)['skills']['load']['extraDirs']);
            $this->assertFalse(\ocKokkoExtraDirs($paths, true));
            $this->assertTrue(\ocKokkoExtraDirs($paths, false));
            $this->assertFalse(\ocKokkoExtraDirs($paths, false));
        }
        $download = static function (): bool { throw new \RuntimeException('Must not download'); };
        touch($paths['state'].'/pmss-kokko-disabled');
        \ocKokkoApply($paths, false, $download);
        unlink($paths['state'].'/pmss-kokko-disabled');
        mkdir($paths['state'].'/pmss-kokko', 0700);
        file_put_contents($paths['state'].'/pmss-kokko/keep', 'user data');
        \ocKokkoApply($paths, false, $download);
        \ocKokkoApply($paths, true, $download);
        $this->assertSame('user data', file_get_contents($paths['state'].'/pmss-kokko/keep'));
    }

    public function testUpdateMigratesManagedProviderBeforeTheExistingGatewayRestart(): void
    {
        $provider = array('baseUrl' => 'https://ai.example/v1', 'api' => 'openai-completions',
            'apiKey' => array('source' => 'env', 'provider' => 'default', 'id' => 'CUSTOM_API_KEY'),
            'models' => array(array('id' => 'default', 'name' => 'default')));
        $paths = $this->openClawConfigFixture(array('models' => array('providers' => array('virsilipas' => $provider))));
        $paths['npm'] = $paths['home'].'/npm.php';
        file_put_contents($paths['npm'], '<?php // No network.');
        \ocPrivateMarkerWrite($paths['state'].'/pmss-virsilipas', "virsilipas\n");
        touch($paths['state'].'/pmss-kokko-disabled');
        mkdir($paths['home'].'/stubs', 0700);
        file_put_contents($paths['home'].'/stubs/quota', "#!/bin/sh\nexit 0\n");
        chmod($paths['home'].'/stubs/quota', 0700);
        file_put_contents($paths['home'].'/.media-stack-port-openclaw', "23000\n");
        $prior = getenv('PATH');
        putenv('PATH='.$paths['home'].'/stubs:'.$prior);
        try {
            // The fake gateway exits; no real service or listening socket is created.
            $this->assertThrowsRuntime(static function () use ($paths): void { \ocUpdate($paths); }, 'Gateway did not stay running');
        } finally { $prior === false ? putenv('PATH') : putenv('PATH='.$prior); }
        $model = \ocConfigRead($paths)['models']['providers']['virsilipas']['models'][0];
        $this->assertSame(array('text'), $model['input']);
        $this->assertSame(true, $model['compat']['supportsUsageInStreaming']);
        $calls = array_map(static function (string $line): array { return json_decode($line, true); },
            explode("\n", trim(file_get_contents($paths['home'].'/calls'))));
        $this->assertSame(array('--version', 'doctor', 'config', 'config', 'gateway'), array_column($calls, 0));
        $this->assertSame('models.providers.virsilipas.models', $calls[2][2]);
    }

    /** Minimal tar headers let tests express unsafe names and links without extracting them. */
    private function openClawTarFixture(string $name, string $type = '0'): string
    {
        $header = str_pad($name, 100, "\0").sprintf('%07o', 0644)."\0".str_repeat("0000000\0", 2)
            ."00000000000\0"."00000000000\0".str_repeat(' ', 8).$type.str_pad('target', 100, "\0")
            ."ustar\0".'00';
        $header = str_pad($header, 512, "\0");
        $sum = array_sum(unpack('C*', $header));
        $header = substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
        $archive = $this->pmssMakeTempPath('pmss-oc-tar-', '.tar.gz');
        file_put_contents($archive, gzencode($header.str_repeat("\0", 1024)));
        return $archive;
    }

    public function testKokkoArchiveRejectsTraversalAbsoluteLinksAndForeignRoot(): void
    {
        \ocKokkoArchiveValidate(__DIR__.'/../fixtures/mcxKokko-v0.1.1.tar.gz');
        foreach (array(array('/absolute'), array('mcxKokko-0.1.1/../escape'), array('other/skills/file'),
            array('mcxKokko-0.1.1/link', '1'), array('mcxKokko-0.1.1/link', '2'),
            array('mcxKokko-0.1.1/device', '3'), array("mcxKokko-0.1.1/new\nline")) as $row) {
            $archive = $this->openClawTarFixture($row[0], $row[1] ?? '0');
            $this->assertThrowsRuntime(static function () use ($archive): void { \ocKokkoArchiveValidate($archive); });
        }
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
        $this->assertStringContainsString("export OPENCLAW_SUPERVISOR_MODE=external\n", $wrapper);
        $this->assertSame(false, strpos($wrapper, $credentials['key']));
        $this->assertTrue(strpos($wrapper, 'ocVirsilipasCredentials') !== false);
    }

    public function testWrapperLoadsGatewayTokenFromPrivateEnvForGatewayCommands(): void
    {
        if (posix_geteuid() === 0) throw new SkipTest('installer refuses root');
        $home = $this->pmssMakeTempDir('pmss-oc-wrapper-');
        $paths = \ocPaths($home);
        mkdir(dirname($paths['node']), 0700, true);
        symlink(PHP_BINARY, $paths['node']);
        mkdir(dirname($paths['entry']), 0700, true);
        mkdir($paths['state'], 0700);
        \ocEnsureToken($paths);
        $token = trim(substr(file_get_contents($paths['env']), strlen('OPENCLAW_GATEWAY_TOKEN=')));
        file_put_contents($paths['entry'], '<?php echo getenv("OPENCLAW_GATEWAY_TOKEN"), "|", getenv("OPENCLAW_SUPERVISOR_MODE"), "|", implode(" ", array_slice($argv, 1));');
        $wrapper = $home.'/openclaw-wrapper';
        file_put_contents($wrapper, \ocCliWrapper($paths));
        chmod($wrapper, 0700);
        $env = getenv();
        $env['HOME'] = $home;
        unset($env['OPENCLAW_GATEWAY_TOKEN'], $env['CUSTOM_API_KEY']);
        list($rc, $output) = \ocRun(array($wrapper, 'gateway', 'restart'), $env);
        $this->assertSame(0, $rc);
        $this->assertSame($token.'|external|gateway restart', $output);
        // A caller's own token wins, and a linked or malformed env file is never read.
        $env['OPENCLAW_GATEWAY_TOKEN'] = 'caller';
        $this->assertSame('caller|external|x', \ocRun(array($wrapper, 'x'), $env)[1]);
        unset($env['OPENCLAW_GATEWAY_TOKEN']);
        rename($paths['env'], $home.'/elsewhere.env');
        symlink($home.'/elsewhere.env', $paths['env']);
        $this->assertSame('|external|x', \ocRun(array($wrapper, 'x'), $env)[1]);
        unlink($paths['env']);
        file_put_contents($paths['env'], "OPENCLAW_GATEWAY_TOKEN=\$(touch pwned)\n");
        $this->assertSame('|external|x', \ocRun(array($wrapper, 'x'), $env)[1]);
        $this->assertFalse(file_exists($home.'/pwned'));
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
        $this->assertSame('external', $env['OPENCLAW_SUPERVISOR_MODE']);
        $this->assertSame('external', $env['OPENCLAW_SERVICE_REPAIR_POLICY']);
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
