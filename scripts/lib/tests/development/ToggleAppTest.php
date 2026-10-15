<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/watchdog.php';
require_once dirname(__DIR__, 2).'/lighttpd/proxyFragments.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/toggleAppRuntime.php';

/** Security contracts for the two opt-in toggle apps. */
class ToggleAppTest extends TestCase
{
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

    public function testProxyRetainsPrefixAndSkeletonShipsEndpoints(): void
    {
        foreach (['slskd', 'copyparty'] as $app) {
            $proxy = \pmssLighttpdManagedProxyFragment($app, 'alice', 23001);
            $this->assertStringContainsString('^/user-alice/'.$app.'/', $proxy);
            $this->assertStringNotContainsString('map-urlpath', $proxy);
            $this->pmssAssertRepoFileContainsString('scripts/lib/update/users/filesystem.php', "'www/{$app}.php'");
            $this->pmssAssertRepoFileContainsString('etc/skel/www/scriptsIncAppProcess.php', "'endpoint' => '{$app}.php'");
            $this->pmssAssertRepoFileContainsString('etc/seedbox/config/root.cron', 'check'.ucfirst($app).'Instances.php');
        }
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/users/filesystem.php', "'www/toggleAppRuntime.php'");
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
