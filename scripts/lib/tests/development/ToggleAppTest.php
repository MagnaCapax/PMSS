<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/watchdog.php';
require_once dirname(__DIR__, 2).'/user/toggleApp.php';
require_once dirname(__DIR__, 2).'/lighttpd/proxyFragments.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/toggleAppRuntime.php';

/** Security contracts for the two opt-in toggle apps. */
class ToggleAppTest extends TestCase
{
    public function testCatalogIsPureDataAndKeepsLegacyValues(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/toggleAppCatalog.php');
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) $this->assertFalse(in_array($token[0], array(T_FUNCTION, T_VARIABLE), true));
            else $this->assertFalse($token === '`');
        }
        $this->assertFalse((bool) preg_match('/\bfn\s*\(/', $source));
        $catalog = require $this->pmssRepoPath('etc/skel/www/toggleAppCatalog.php');
        $this->assertSame(array('slskd', 'copyparty', 'qui', 'scryer'), array_keys($catalog));
        $this->assertSame(array('.slskdEnable', '.config/slskd/slskd.yml', array('slskd-peer')),
            array($catalog['slskd']['enable'], $catalog['slskd']['config'], $catalog['slskd']['extraPortServices']));
        $this->assertSame(array('.copypartyEnable', '.config/copyparty/copyparty.conf', array()),
            array($catalog['copyparty']['enable'], $catalog['copyparty']['config'], $catalog['copyparty']['extraPortServices']));
        $this->assertSame("cd '/opt/slskd' && nohup '/opt/slskd/slskd' '--config' '/home/bo/.config/slskd/slskd.yml'",
            \pmssToggleAppLaunchCommand($catalog['slskd'], '/home/bo/'.$catalog['slskd']['config'], '/home/bo'));
        $this->assertSame("nohup 'python3' '/usr/local/bin/copyparty-sfx.py' '-c' '/home/bo/.config/copyparty/copyparty.conf'",
            \pmssToggleAppLaunchCommand($catalog['copyparty'], '/home/bo/'.$catalog['copyparty']['config'], '/home/bo'));
        $this->assertSame(array('.config/slskd/slskd.yml' => "web:\n  port: 23001\n  ip_address: 127.0.0.1\n  https:\n    disabled: true\n  url_base: /user-bo/slskd\n  authentication:\n    disabled: false\n    username: bo\n    password: secret\nsoulseek:\n  listen_ip_address: 0.0.0.0\n  listen_port: 23002\n"),
            \pmssToggleAppConfigs('slskd', 'bo', '/home/bo', 23001, 'secret', 23002));
        $this->assertSame(array('.config/copyparty/copyparty.conf' => "[global]\n  i: 127.0.0.1\n  p: 23001\n  rp-loc: /user-bo/copyparty\n  xff-hdr: X-Forwarded-For\n  rproxy: -1\n\n[accounts]\n  bo: secret\n\n[/]\n  /home/bo/data\n  accs:\n    A: bo\n"),
            \pmssToggleAppConfigs('copyparty', 'bo', '/home/bo', 23001, 'secret', 0));
        $expectedProxy = <<<'PROXY'
# PMSS-managed: {LABEL} reverse proxy.

$HTTP["url"] == "/user-bo/{ID}" {
  url.redirect = ( "" => "/user-bo/{ID}/" )
}

$HTTP["url"] =~ "^/user-bo/{ID}/" {
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => 23001
  ) ) ),
  proxy.forwarded = ( "for" => 1,
                      "host" => 1,
                      "by" => 1
  ),
  proxy.header = (
      "upgrade" => "enable"
  )
}

PROXY;
        foreach ($catalog as $app => $row) {
            $this->assertSame(array('.pmss/apps/'.$app.'.secured', '.pmss/apps/'.$app.'.state',
                '.pmss/apps/'.$app.'.restart', '.pmss/apps/'.$app.'.login'),
                array($row['securedMarker'], $row['stateFile'], $row['restartFile'], $row['loginFile']));
            $this->assertSame(str_replace(array('{LABEL}', '{ID}'), array($row['label'], $app), $expectedProxy)."\n",
                \pmssLighttpdManagedProxyFragment($app, 'bo', 23001));
        }
    }

    public function testRootCatalogGuardRefusesUnsafeFilesAndDirectories(): void
    {
        $dir = $this->pmssMakeTempDir('toggle-catalog-', 0700);
        $path = $dir.'/catalog.php';
        copy($this->pmssRepoPath('etc/skel/www/toggleAppCatalog.php'), $path);
        chmod($path, 0600);
        $oldMode = getenv('PMSS_TEST_MODE');
        $oldPath = getenv('PMSS_TOGGLE_APP_CATALOG_PATH');
        putenv('PMSS_TEST_MODE=1');
        try {
            putenv('PMSS_TOGGLE_APP_CATALOG_PATH='.$path);
            $this->assertSame(array('slskd', 'copyparty', 'qui', 'scryer'), array_keys(\pmssToggleAppCatalogRead()));
            chmod($path, 0660);
            $this->assertThrowsRuntime(static function (): void { \pmssToggleAppCatalogRead(); });
            chmod($path, 0600);
            chmod($dir, 0770);
            $this->assertThrowsRuntime(static function (): void { \pmssToggleAppCatalogRead(); });
            chmod($dir, 0700);
            $link = $dir.'/link.php';
            symlink($path, $link);
            putenv('PMSS_TOGGLE_APP_CATALOG_PATH='.$link);
            $this->assertThrowsRuntime(static function (): void { \pmssToggleAppCatalogRead(); });
            $cron = $this->pmssRunRepoPhpScriptCommand('scripts/cron/checkToggleApps.php', array(),
                array('PMSS_TEST_MODE' => '1', 'PMSS_TOGGLE_APP_CATALOG_PATH' => $link));
            $this->assertSame(1, $cron['rc']);
            $this->assertStringContainsString('Unsafe toggle-app catalog', $cron['output']);
            $directoryLink = $dir.'-link';
            symlink($dir, $directoryLink);
            putenv('PMSS_TOGGLE_APP_CATALOG_PATH='.$directoryLink.'/catalog.php');
            $this->assertThrowsRuntime(static function (): void { \pmssToggleAppCatalogRead(); });
        } finally {
            $oldMode === false ? putenv('PMSS_TEST_MODE') : putenv('PMSS_TEST_MODE='.$oldMode);
            $oldPath === false ? putenv('PMSS_TOGGLE_APP_CATALOG_PATH') : putenv('PMSS_TOGGLE_APP_CATALOG_PATH='.$oldPath);
        }
    }

    public function testSingleCronAndCatalogOnlyWiring(): void
    {
        $cron = $this->pmssReadRepoFile('etc/seedbox/config/root.cron');
        $this->assertSame(1, substr_count($cron, 'checkToggleApps.php'));
        $this->assertFalse((bool) preg_match('/check(?:Slskd|Copyparty)Instances/', $cron));
        $this->pmssAssertRepoFileContainsString('scripts/terminateUser.php', '/var/lib/pmss/toggle-apps/{$username}-{$toggleApp}.json');
        $this->pmssAssertRepoFileContainsString('scripts/terminateUser.php', "escapeshellarg(\$service)");
    }

    public function testAppIdsStayInCatalogRenderersAndCompatibilityShims(): void
    {
        $paths = array_merge(glob($this->pmssRepoPath('etc/skel/www/*.php')) ?: array(),
            glob($this->pmssRepoPath('scripts/cron/*.php')) ?: array(),
            array($this->pmssRepoPath('scripts/lib/user/toggleApp.php'),
                $this->pmssRepoPath('scripts/lib/lighttpd/proxyFragments.php'),
                $this->pmssRepoPath('scripts/terminateUser.php')));
        foreach ($paths as $path) {
            if (in_array(basename($path), array('toggleAppCatalog.php', 'toggleAppRuntime.php', 'slskd.php', 'copyparty.php'), true)) continue;
            // DroppedNeedle's description names slskd as the client it drives; that is display copy, not wiring.
            $source = str_replace('drive your own slskd or SABnzbd', '', (string) file_get_contents($path));
            $this->assertFalse((bool) preg_match('/slskd|copyparty/i', $source), $path);
        }
    }

    public function testEndpointRejectsUnknownIdsBeforeWritingAndStartsKnownId(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home): void {
            foreach (array('unknown', '../x', 'slskd') as $app) {
                $php = '$_SERVER["REQUEST_METHOD"]="POST";'
                    .'$_SERVER["HTTP_X_REQUESTED_WITH"]="XMLHttpRequest";'
                    .'$_GET["app"]='.var_export($app, true).';$_GET["action"]="start";'
                    .'register_shutdown_function(function(){echo "HTTP=".(http_response_code() ?: 200);});'
                    .'require '.var_export($home.'/www/toggleApp.php', true).';';
                $result = $this->pmssExecShellCommand('cd '.escapeshellarg($home.'/www').' && '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($php));
                $this->assertSame(0, $result['rc'], $result['output']);
                $this->assertStringContainsString($app === 'slskd' ? 'HTTP=200' : 'HTTP=404', $result['output']);
                if ($app !== 'slskd') $this->assertFalse(is_file($home.'/.slskdEnable'));
            }
            $this->assertTrue(is_file($home.'/.slskdEnable'));
            $this->assertSame("not secured\n", file_get_contents($home.'/.pmss/apps/slskd.state'));
        });
    }

    public function testLegacySpecHasNoNewFields(): void
    {
        $this->assertSame(['processName' => 'demo', 'command' => 'true', 'userLogMessage' => 'start'],
            \pmssUserWatchdogServiceSpec('demo', 'true', 'start'));
        $spec = \pmssUserWatchdogServiceSpec('slskd', 'true', 'start', 'slskd',
            ['securedMarker' => '.pmss/apps/slskd.secured', 'crashLoop' => 'slskd']);
        $this->assertSame('slskd', $spec['crashLoop']);
    }

    public function testMissingAndUnsafeMarkerNeverQualify(): void
    {
        $home = $this->pmssMakeTempDir('toggle-marker-');
        $this->assertFalse(\pmssUserWatchdogSecuredMarkerPresent($home, '.pmss/apps/slskd.secured'));
        $this->pmssEnsureDir($home.'/.pmss/apps');
        $this->pmssWriteFile($home.'/.pmss/apps/slskd.secured', 'secured');
        $this->assertTrue(\pmssUserWatchdogSecuredMarkerPresent($home, '.pmss/apps/slskd.secured'));
        $this->assertFalse(\pmssUserWatchdogSecuredMarkerPresent($home, '../other'));
        $this->assertFalse(\pmssUserWatchdogSecuredMarkerPresent($home, '/etc/passwd'));
    }

    public function testFiveFailedStartsGiveUpAndRestartClearsGate(): void
    {
        $state = ['failures' => 0, 'pending' => false, 'restart' => ''];
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $state['pending'] = true;
            $state = \pmssUserWatchdogCrashTransition($state, false, '');
            $this->assertSame($attempt, $state['failures']);
        }
        $this->assertSame(5, $state['failures']);
        $state = \pmssUserWatchdogCrashTransition($state, false, 'slskd.restart.abc');
        $this->assertSame(0, $state['failures']);
        $this->assertFalse($state['pending']);
        $state['pending'] = true;
        $state = \pmssUserWatchdogCrashTransition($state, true, 'slskd.restart.abc');
        $this->assertSame(0, $state['failures']);
    }

    public function testCrashHistoryPersistsOutsideCustomerHome(): void
    {
        $dir = $this->pmssMakeTempDir('toggle-state-');
        $old = getenv('PMSS_TOGGLE_APP_STATE_DIR');
        putenv('PMSS_TOGGLE_APP_STATE_DIR='.$dir);
        try {
            $state = ['failures' => 4, 'pending' => true, 'restart' => ''];
            $this->assertSame($state, \pmssUserWatchdogCrashState('alice', 'slskd', $state));
            $this->assertSame($state, \pmssUserWatchdogCrashState('alice', 'slskd'));
            $this->assertTrue(is_file($dir.'/alice-slskd.json'));
            $this->assertTrue(!empty(\pmssUserWatchdogCrashState('bad-user', 'slskd')['storageError']));
        } finally {
            $old === false ? putenv('PMSS_TOGGLE_APP_STATE_DIR') : putenv('PMSS_TOGGLE_APP_STATE_DIR='.$old);
        }
    }

    public function testRestartSignalChangesMetadataWithoutReadingMarker(): void
    {
        $home = $this->pmssMakeTempDir('toggle-restart-');
        $this->assertTrue(\pmssToggleAppRestartSignal('slskd', $home));
        $first = \pmssUserWatchdogRestartToken($home, 'slskd');
        $this->assertTrue($first !== '');
        $this->assertTrue(\pmssToggleAppRestartSignal('slskd', $home));
        $this->assertTrue(\pmssUserWatchdogRestartToken($home, 'slskd') !== $first);
    }

    public function testBootstrapWritesPrivateLoopbackConfigsAndLogin(): void
    {
        $old = getenv('PMSS_TEST_MODE');
        putenv('PMSS_TEST_MODE=1');
        try {
            foreach (['slskd', 'copyparty'] as $app) {
                $home = $this->pmssMakeTempDir('toggle-'.$app.'-');
                $proxy = \pmssLighttpdManagedProxyFragment($app, 'alice', 23001);
                $this->assertTrue(\pmssToggleAppBootstrap($app, 'alice', $home, 23001, $proxy, $app === 'slskd' ? 23002 : 0));
                $config = $home.'/.config/'.$app.'/'.($app === 'slskd' ? 'slskd.yml' : 'copyparty.conf');
                $login = $home.'/.pmss/apps/'.$app.'.login';
                $body = (string) file_get_contents($config);
                $credentials = (string) file_get_contents($login);
                $this->assertSame(0600, fileperms($config) & 0777);
                $this->assertSame(0600, fileperms($login) & 0777);
                $this->assertStringContainsString('127.0.0.1', $body);
                $this->assertStringContainsString('/user-alice/'.$app, $body);
                $this->assertStringContainsString('alice', $credentials);
                $this->assertStringNotContainsString('slskd\nslskd', $credentials);
                $this->assertTrue((bool) preg_match('/alice\\n[a-f0-9]{48}\\n/', $credentials));
                $this->assertTrue(is_file($home.'/.pmss/apps/'.$app.'.secured'));
                $this->assertStringContainsString('^/user-alice/'.$app.'/', (string) file_get_contents($home.'/.lighttpd/custom.d/pmss-'.$app.'.conf'));
                if ($app === 'slskd') {
                    $this->assertStringContainsString('disabled: true', $body);
                    $this->assertStringContainsString('authentication:', $body);
                    $this->assertStringContainsString("soulseek:\n  listen_ip_address: 0.0.0.0\n  listen_port: 23002\n", $body);
                    $this->assertStringNotContainsString('50300', $body);
                } else {
                    $this->assertStringContainsString('rp-loc: /user-alice/copyparty', $body);
                    $this->assertStringContainsString("[/]\n  {$home}/data\n  accs:\n    A: alice", $body);
                }
            }
        } finally {
            $old === false ? putenv('PMSS_TEST_MODE') : putenv('PMSS_TEST_MODE='.$old);
        }
    }

    public function testSlskdNeedsItsOwnPeerPort(): void
    {
        $old = getenv('PMSS_TEST_MODE');
        putenv('PMSS_TEST_MODE=1');
        try {
            $home = $this->pmssMakeTempDir('toggle-peer-');
            $proxy = \pmssLighttpdManagedProxyFragment('slskd', 'alice', 23001);
            $this->assertFalse(\pmssToggleAppBootstrap('slskd', 'alice', $home, 23001, $proxy));
            $this->assertFalse(\pmssToggleAppBootstrap('slskd', 'alice', $home, 23001, $proxy, 23001));
            $this->assertFalse(is_file($home.'/.pmss/apps/slskd.secured'));
        } finally {
            $old === false ? putenv('PMSS_TEST_MODE') : putenv('PMSS_TEST_MODE='.$old);
        }
    }

    public function testQuiCliSecuresOnlyAfterAccountExists(): void
    {
        $home = $this->pmssMakeTempDir('toggle-qui-');
        $fixture = $home.'/qui-fixture';
        $this->pmssWriteFile($fixture, <<<'PHP'
#!/usr/bin/env php
<?php
$config = $argv[3] ?? '';
file_put_contents(getenv('PMSS_TEST_QUI_LOG'), json_encode($argv)."\n", FILE_APPEND);
file_put_contents(getenv('PMSS_TEST_QUI_STDIN'), stream_get_contents(STDIN));
if (getenv('PMSS_TEST_QUI_MODE') === 'fail') exit(2);
if (getenv('PMSS_TEST_QUI_MODE') === 'exists' && $argv[1] === 'create-user') {
    fwrite(STDERR, 'User account already exists. Only one user account is allowed.');
    exit(1);
}
file_put_contents($config.'/qui.db', 'account');
PHP
        );
        chmod($fixture, 0700);
        $oldMode = getenv('PMSS_TEST_MODE');
        putenv('PMSS_TEST_MODE=1');
        putenv('PMSS_TEST_QUI_BINARY='.$fixture);
        putenv('PMSS_TEST_QUI_LOG='.$home.'/argv.log');
        putenv('PMSS_TEST_QUI_STDIN='.$home.'/stdin');
        $proxy = \pmssLighttpdManagedProxyFragment('qui', 'bo', 23001);
        try {
            $this->assertTrue(\pmssToggleAppBootstrap('qui', 'bo', $home, 23001, $proxy));
            $config = $home.'/.config/qui/config.toml';
            $this->assertSame("host = \"127.0.0.1\"\nport = 23001\nbaseUrl = \"/user-bo/qui/\"\ncheckForUpdates = false\ndisableSelfUpdate = true\n", file_get_contents($config));
            $this->assertSame(0600, fileperms($config) & 0777);
            $login = $home.'/.pmss/apps/qui.login';
            $this->assertSame(0600, fileperms($login) & 0777);
            $this->assertSame(explode("\n", trim(file_get_contents($login)))[1]."\n", file_get_contents($home.'/stdin'));
            unlink($home.'/.config/qui/qui.db');
            putenv('PMSS_TEST_QUI_MODE=exists');
            $this->assertTrue(\pmssToggleAppBootstrap('qui', 'bo', $home, 23001, $proxy));
            $argv = file($home.'/argv.log', FILE_IGNORE_NEW_LINES);
            $this->assertSame(3, count($argv));
            $this->assertStringContainsString('change-password', $argv[2]);
            foreach ($argv as $line) $this->assertStringNotContainsString(explode("\n", trim(file_get_contents($login)))[1], $line);
            unlink($login);
            putenv('PMSS_TEST_QUI_MODE=fail');
            $this->assertFalse(\pmssToggleAppBootstrap('qui', 'bo', $home, 23001, $proxy));
            $this->assertFalse(is_file($home.'/.pmss/apps/qui.secured'));
        } finally {
            $oldMode === false ? putenv('PMSS_TEST_MODE') : putenv('PMSS_TEST_MODE='.$oldMode);
            foreach (array('PMSS_TEST_QUI_BINARY', 'PMSS_TEST_QUI_LOG', 'PMSS_TEST_QUI_STDIN', 'PMSS_TEST_QUI_MODE') as $key) putenv($key);
        }
    }

    public function testScryerWritesPrivateBootstrapFilesAndQuotedLaunch(): void
    {
        $home = $this->pmssMakeTempDir('toggle-scryer-');
        $old = getenv('PMSS_TEST_MODE');
        putenv('PMSS_TEST_MODE=1');
        try {
            $proxy = \pmssLighttpdManagedProxyFragment('scryer', 'bo', 23001);
            $this->assertTrue(\pmssToggleAppBootstrap('scryer', 'bo', $home, 23001, $proxy));
            $env = $home.'/.config/scryer/scryer.env';
            $password = $home.'/.pmss/apps/scryer.pw';
            $this->assertSame("SCRYER_BIND=127.0.0.1:23001\nSCRYER_BASE_PATH=/user-bo/scryer\nSCRYER_AUTH_ENABLED=true\nSCRYER_ADMIN_USERNAME=bo\nSCRYER_ADMIN_PASSWORD_FILE={$home}/.pmss/apps/scryer.pw\n", file_get_contents($env));
            $this->assertSame(0600, fileperms($env) & 0777);
            $this->assertSame(0600, fileperms($password) & 0777);
            $this->assertSame(explode("\n", trim(file_get_contents($home.'/.pmss/apps/scryer.login')))[1]."\n", file_get_contents($password));
            $row = (require $this->pmssRepoPath('etc/skel/www/toggleAppCatalog.php'))['scryer'];
            $launch = \pmssToggleAppLaunchCommand($row, "/home/bo/a'b/.config/scryer/scryer.env", "/home/bo/a'b");
            $shell = 'set -a; . '.escapeshellarg("/home/bo/a'b/.config/scryer/scryer.env")
                .'; set +a; exec '.escapeshellarg('/opt/scryer/scryer').' '.escapeshellarg('--data-dir')
                .' '.escapeshellarg("/home/bo/a'b/.local/share/scryer");
            $this->assertSame('nohup '.escapeshellarg('/bin/sh').' -c '.escapeshellarg($shell), $launch);
        } finally {
            $old === false ? putenv('PMSS_TEST_MODE') : putenv('PMSS_TEST_MODE='.$old);
        }
    }

    public function testRepeatedBootstrapLeavesProxyAndMarkerUntouched(): void
    {
        // The watchdog bootstraps every cycle; a rewrite would make the lighttpd watchdog restart the account.
        $old = getenv('PMSS_TEST_MODE');
        putenv('PMSS_TEST_MODE=1');
        try {
            foreach (['slskd', 'copyparty'] as $app) {
                $home = $this->pmssMakeTempDir('toggle-idem-'.$app.'-');
                $proxy = \pmssLighttpdManagedProxyFragment($app, 'alice', 23001);
                $peer = $app === 'slskd' ? 23002 : 0;
                $this->assertTrue(\pmssToggleAppBootstrap($app, 'alice', $home, 23001, $proxy, $peer));
                $paths = [$home.'/.lighttpd/custom.d/pmss-'.$app.'.conf', $home.'/.pmss/apps/'.$app.'.secured',
                    $home.'/.config/'.$app.'/'.($app === 'slskd' ? 'slskd.yml' : 'copyparty.conf')];
                clearstatcache();
                $before = array_map(static function (string $path): array { $s = stat($path); return [$s['ino'], $s['mtime']]; }, $paths);
                $this->assertTrue(\pmssToggleAppBootstrap($app, 'alice', $home, 23001, $proxy, $peer));
                clearstatcache();
                $after = array_map(static function (string $path): array { $s = stat($path); return [$s['ino'], $s['mtime']]; }, $paths);
                $this->assertSame($before, $after);
            }
        } finally {
            $old === false ? putenv('PMSS_TEST_MODE') : putenv('PMSS_TEST_MODE='.$old);
        }
    }

    public function testDetachedLaunchReturnsWhileTheAppKeepsRunning(): void
    {
        // The watchdog passthru()s the start command and waits for EOF on its stdout pipe.
        $marker = $this->pmssMakeTempDir('toggle-detach-').'/alive';
        $command = \pmssToggleAppDetachedGroup('true', 'sleep 3; touch '.escapeshellarg($marker));
        $started = microtime(true);
        $proc = proc_open('bash -c '.escapeshellarg($command), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($proc);
        $this->assertTrue(microtime(true) - $started < 2.0);
        $this->assertFalse(is_file($marker));
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/toggleApp.php', 'pmssToggleAppDetachedGroup($conditions, $launch)');
        // Files the app creates stay private even under a loose caller umask.
        $created = dirname($marker).'/created';
        exec('umask 022; bash -c '.escapeshellarg(\pmssToggleAppDetachedGroup('true', 'touch '.escapeshellarg($created))));
        for ($i = 0; $i < 50 && !is_file($created); $i++) usleep(100000);
        clearstatcache();
        $this->assertSame(0600, fileperms($created) & 0777);
    }

    public function testProxyRetainsPrefixAndSkeletonShipsEndpoints(): void
    {
        foreach (['slskd', 'copyparty'] as $app) {
            $proxy = \pmssLighttpdManagedProxyFragment($app, 'alice', 23001);
            $this->assertStringContainsString('^/user-alice/'.$app.'/', $proxy);
            $this->assertStringNotContainsString('map-urlpath', $proxy);
            $this->pmssAssertRepoFileContainsString('scripts/lib/update/users/filesystem.php', "'www/{$app}.php'");
            $this->pmssAssertRepoFileContainsString('etc/skel/www/scriptsIncAppProcess.php', "'endpoint' => 'toggleApp.php?app='");
        }
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/users/filesystem.php', "'www/toggleAppRuntime.php'");
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/root.cron', 'checkToggleApps.php');
    }

    public function testSiblingProxyConflictPreventsSecuredMarker(): void
    {
        $home = $this->pmssMakeTempDir('toggle-proxy-conflict-');
        $dir = $home.'/.lighttpd/custom.d';
        $this->pmssEnsureDir($dir);
        $this->pmssWriteFile($dir.'/custom.conf', '$HTTP["url"] =~ "^/user-alice/slskd/" {}');
        $old = getenv('PMSS_TEST_MODE');
        putenv('PMSS_TEST_MODE=1');
        try {
            $this->assertFalse(\pmssToggleAppBootstrap('slskd', 'alice', $home, 23001,
                \pmssLighttpdManagedProxyFragment('slskd', 'alice', 23001)));
            $this->assertFalse(is_file($home.'/.pmss/apps/slskd.secured'));
        } finally {
            $old === false ? putenv('PMSS_TEST_MODE') : putenv('PMSS_TEST_MODE='.$old);
        }
    }
}
