<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
ob_start();
require_once dirname(__DIR__, 4).'/etc/skel/www/apps.php';
ob_end_clean();

/** Customer Apps tab contract: live controls and a closed wiki catalog. */
class AppsCatalogTest extends TestCase
{
    public function testSelfHostedCatalogHasExpectedRowsAndAnchors(): void
    {
        $catalog = \pmssAppsCatalogRead();
        $expected = array('Media_servers_and_live_TV', 'Comics,_books_and_audiobooks',
            'Downloads_and_requests', 'Library_automation', 'Library_maintenance_and_transcoding',
            'Photos,_files_and_passwords', 'Dashboards,_chat_and_desktop');
        $urls = \pmssAppsAllowedUrlsRead();
        $names = array();
        $anchors = array();
        foreach ($catalog as $category => $apps) {
            $anchors[] = substr($urls[$category], strpos($urls[$category], '#') + 1);
            foreach ($apps as $app) {
                $names[] = $app[0];
                $this->assertTrue(is_bool($app[1]));
                $this->assertTrue(is_string($app[2]) && $app[2] !== '');
                $this->assertTrue(is_string($app[3]));
            }
        }
        $this->assertSame($expected, $anchors);
        $this->assertSame(39, count($names));
        $this->assertSame(count($names), count(array_unique($names)));
    }

    public function testFreshHomeHasInstallBannerAndOnlyAllowedWikiLinks(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('apps.php', [], ['minBytes' => 4000]);
        $this->assertStringContainsString('Media Stack is not installed', $html);
        $this->assertStringContainsString('Install Media Stack', $html);
        $this->assertStringContainsString('function pmssActionRequest(action, passwordValue)', $html);
        preg_match_all('/href="([^"]+)"[^>]*>Setup guide &#8599;<\/a>/', $html, $matches);
        $this->assertSame(39, count($matches[1]));
        $allowed = array_values(\pmssAppsAllowedUrlsRead());
        foreach ($matches[1] as $href) {
            $this->assertTrue(in_array(html_entity_decode($href, ENT_QUOTES, 'UTF-8'), $allowed, true), $href);
        }
    }

    public function testInstalledStackRendersRuntimeRowsAndSecurityState(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $this->pmssEnsureDir($home.'/.config/jellyfin', 0700);
            $this->pmssEnsureDir($home.'/.config/radarr', 0700);
            $this->pmssWriteFile($home.'/.radarrDisable', '');
            $this->pmssWriteFile($home.'/.media-stack-status.json', json_encode(array(
                'state' => 'degraded', 'apps' => array(
                    'jellyfin' => array('state' => 'running'),
                    'radarr' => array('state' => 'stopped'),
                    'sonarr' => array('state' => 'failed'),
                ),
            )));
            $html = $render('apps.php', ['minBytes' => 4000])['stdout'];
            $this->assertStringNotContainsString('Media Stack is not installed', $html);
            $this->assertStringContainsString('Stream your library to TV, phone and browser', $html);
            $this->assertStringContainsString('>Not running</span>', $html);
            $this->assertStringContainsString('>Stopped by you</span>', $html);
            $this->assertStringContainsString('No login set', $html);
            $this->assertStringContainsString('data-action="start">Start</button>', $html);
            $this->assertStringContainsString('data-action="log">Show log</button>', $html);
        });
    }

    public function testActionsUseExistingCustomerEndpointsOnly(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/apps.php');
        $shared = $this->pmssReadRepoFile('etc/skel/www/scriptsInc.php');
        $this->assertStringContainsString("require_once __DIR__.'/scriptsInc.php'", $source);
        $this->assertStringContainsString("require_once __DIR__.'/userMediaStackPanel.php'", $source);
        $this->assertStringNotContainsString('/scripts/', $source);
        $this->assertStringNotContainsString('location.reload', $source);
        $this->assertStringNotContainsString('<form', $source);
        $this->assertStringContainsString('rtorrentRestart.php?action=', $source);
        foreach (array('pmssMediaStackStart', 'pmssMediaStackSecureApp') as $function) {
            $this->assertStringContainsString($function, $source);
            $this->assertStringContainsString('function '.$function.'(', $shared);
        }
        foreach (array('mediaStack.php?action=status', 'mediaStack.php?action=', 'mediaStack.php') as $route) {
            $this->assertStringContainsString($route, $shared);
        }
        $this->assertStringContainsString("'endpoint' => 'qbittorrent.php'", $this->pmssReadRepoFile('etc/skel/www/scriptsInc.php'));
        $this->assertStringContainsString('pmssActionScriptJs()', $this->pmssReadRepoFile('etc/skel/www/welcome.php'));
        $this->assertStringContainsString("'www/appsRuntime.php'", $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php'));
    }

    public function testLiveStatusJsonAndBoundedEscapedLog(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $this->pmssEnsureDir($home.'/.config/jellyfin', 0700);
            $this->pmssWriteFile($home.'/.jellyfinDisable', '');
            $json = $render('apps.php', array('query' => 'status=1', 'minBytes' => 100))['stdout'];
            $status = json_decode($json, true);
            $this->assertTrue(is_array($status));
            $this->assertSame('off', $status['apps']['jellyfin']['state']);
            $this->assertTrue(isset($status['apps']['jellyfin']['security']));
            $this->assertStringContainsString('Stopped by you', $status['rows']['jellyfin']);
            $this->pmssWriteRelativeFile($home, '.config/jellyfin/log/jellyfin.log', str_repeat("<script>\n", 25));
            $log = \pmssAppsLogTailRead($home, 'jellyfin');
            $this->assertSame(20, count(explode("\n", $log)));
            $this->assertStringNotContainsString('<script>', $log);
            $this->assertStringContainsString('&lt;script&gt;', $log);
            $this->pmssWriteRelativeFile($home, '.config/jellyfin/log/jellyfin.log', str_repeat('x', 800));
            $this->assertSame(300, strlen(\pmssAppsLogTailRead($home, 'jellyfin')));
        });
    }

    public function testNativeProbeExcludesSameNamedContainerBinary(): void
    {
        $root = $this->pmssMakeTempDir('pmss-native-proc-');
        $native = $root.'/usr/bin/qbittorrent-nox';
        $container = $root.'/overlay/bin/qbittorrent-nox';
        $this->pmssWriteFile($native, 'native');
        $this->pmssWriteFile($container, 'container');
        $this->pmssEnsureDir($root.'/proc/123');
        $this->pmssEnsureDir($root.'/proc/456');
        $this->pmssEnsureDir($root.'/proc/self/ns');
        @symlink('mnt:[host]', $root.'/proc/self/ns/mnt');
        $this->pmssEnsureDir($root.'/proc/555');
        foreach (array(123 => 'mnt:[host]', 456 => 'mnt:[guest]', 555 => 'mnt:[guest]') as $pid => $namespace) {
            $this->pmssEnsureDir($root.'/proc/'.$pid.'/ns');
            @symlink($namespace, $root.'/proc/'.$pid.'/ns/mnt');
        }
        @symlink($native, $root.'/proc/123/exe');
        @symlink($container, $root.'/proc/456/exe');
        @symlink($native, $root.'/proc/555/exe');
        $this->assertSame(array(123), \pmssCustomerNativePidsRead(array($native), $root.'/proc', fileowner($root.'/proc/123')));
        $this->assertSame(array(), \pmssCustomerNativePidsRead(array($native), $root.'/proc', -1));
        $signals = array();
        $sent = \pmssCustomerNativeSignal(array($native), 15, $root.'/proc', fileowner($root.'/proc/123'),
            static function ($pid, $signal) use (&$signals): bool { $signals[] = array($pid, $signal); return true; });
        $this->assertSame(1, $sent);
        $this->assertSame(array(array(123, 15)), $signals);
        $python = $this->pmssWriteFile($root.'/usr/bin/python3', 'interpreter');
        $deluged = $this->pmssWriteFile($root.'/usr/bin/deluged', '#!'.$python."\n");
        $guestPython = $this->pmssWriteFile($root.'/overlay/bin/python3', 'guest interpreter');
        foreach (array(789 => $python, 999 => $guestPython) as $pid => $exe) {
            $this->pmssEnsureDir($root.'/proc/'.$pid);
            $this->pmssEnsureDir($root.'/proc/'.$pid.'/ns');
            @symlink($pid === 789 ? 'mnt:[host]' : 'mnt:[guest]', $root.'/proc/'.$pid.'/ns/mnt');
            @symlink($exe, $root.'/proc/'.$pid.'/exe');
            $this->pmssWriteFile($root.'/proc/'.$pid.'/cmdline', $exe."\0".$deluged."\0");
        }
        $this->assertSame(array(789), \pmssCustomerNativePidsRead(array($deluged), $root.'/proc', fileowner($root.'/proc/789')));
    }

    public function testEveryStateEndpointRejectsGetAndAcceptsAjaxPostGate(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home): void {
            foreach (array('qbittorrent.php', 'deluge.php', 'rclone.php', 'rtorrentRestart.php',
                'lighttpdRestart.php', 'mediaStack.php') as $endpoint) {
                $action = $endpoint === 'mediaStack.php' ? 'app-stop' : 'unknown';
                $get = $this->pmssAppsEndpointProbe($home, $endpoint, $action, 'GET', true);
                $this->assertStringContainsString('HTTP=405', $get, $endpoint);
                $plainPost = $this->pmssAppsEndpointProbe($home, $endpoint, $action, 'POST', false);
                $this->assertStringContainsString('HTTP=403', $plainPost, $endpoint);
                $post = $this->pmssAppsEndpointProbe($home, $endpoint, $action, 'POST', true);
                $this->assertStringNotContainsString('HTTP=405', $post, $endpoint);
                $this->assertStringNotContainsString('HTTP=403', $post, $endpoint);
            }
        });
    }

    public function testWelcomeStillRendersControlsUsingPostRequests(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('welcome.php', array(), array('minBytes' => 10000));
        $this->assertStringContainsString('rtorrentRestart.php', $html);
        $this->assertStringContainsString('lighttpdRestart.php?action=confirm-restart', $html);
        $shared = $this->pmssReadRepoFile('etc/skel/www/scriptsInc.php');
        $this->assertStringContainsString("type: action.type || 'POST'", $shared);
        $this->assertStringContainsString("headers: {'X-Requested-With': 'XMLHttpRequest'}", $shared);
    }

    public function testAppsShowsUpdatingNoticeWhenGuivHelperIsOld(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render, string $runRoot): void {
            // A healed, older scriptsInc.php may lack helpers used by a newer apps.php.
            $this->pmssWriteFile($home.'/www/scriptsInc.php', "<?php\n");
            $bootstrap = $runRoot.'/php-cli-bootstrap.php';
            file_put_contents($bootstrap, "register_shutdown_function(function () { echo ' HTTP='.(http_response_code() ?: 200); });\n", FILE_APPEND);
            $result = $render('apps.php', array('minBytes' => 100));
            $this->assertSame(0, $result['rc']);
            $this->assertStringContainsString('The Apps page is being updated on this server. Reload it in a few minutes.', $result['stdout']);
            $this->assertStringContainsString('HTTP=200', $result['stdout']);
        });
    }

    public function testLegacyToggleShellCommandsStillRunForAjaxPost(): void
    {
        $root = $this->pmssMakeTempDir('pmss-toggle-legacy-');
        $marker = $root.'/enabled';
        $disabled = $root.'/disabled';
        $restarted = $root.'/restarted';
        $started = $root.'/started';
        $source = '<?php require '.var_export($this->pmssRepoPath('etc/skel/www/scriptsInc.php'), true).';'
            .'$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["HTTP_X_REQUESTED_WITH"]="XMLHttpRequest";'
            .'$marker='.var_export($marker, true).';'
            .'$start=static function () { file_put_contents('.var_export($started, true).', "yes"); };'
            .'file_put_contents($marker, "on");'
            .'$_POST["action"]="disable";'
            .'pmssFrontendToggleAction($marker, $start, '.var_export('printf disabled > '.escapeshellarg($disabled), true).', '.var_export('printf restarted > '.escapeshellarg($restarted), true).');'
            .'$_POST["action"]="restart";'
            .'pmssFrontendToggleAction($marker, $start, '.var_export('printf disabled > '.escapeshellarg($disabled), true).', '.var_export('printf restarted > '.escapeshellarg($restarted), true).');';
        $script = $this->pmssWriteFile($root.'/toggle.php', $source);
        $result = $this->pmssExecShellCommand(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script));
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertFalse(is_file($marker));
        $this->assertSame('disabled', file_get_contents($disabled));
        $this->assertSame('restarted', file_get_contents($restarted));
        $this->assertSame('yes', file_get_contents($started));
    }

    private function pmssAppsEndpointProbe(string $home, string $endpoint, string $action, string $method, bool $ajax): string
    {
        $php = '$_SERVER["REQUEST_METHOD"]='.var_export($method, true).';'
            .'$_SERVER["HTTP_X_REQUESTED_WITH"]='.var_export($ajax ? 'XMLHttpRequest' : '', true).';'
            .'$_GET["action"]='.var_export($action, true).';'
            .'register_shutdown_function(function(){echo "HTTP=".(http_response_code() ?: 200);});'
            .'require '.var_export($home.'/www/'.$endpoint, true).';';
        $command = 'cd '.escapeshellarg($home.'/www').' && '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($php);
        $result = $this->pmssExecShellCommand($command);
        $this->assertSame(0, $result['rc'], $result['output']);
        return $result['output'];
    }
}
