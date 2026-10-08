<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/scripts/dev/appsLsioCatalogBuild.php';
ob_start();
require_once dirname(__DIR__, 4).'/etc/skel/www/apps.php';
ob_end_clean();

/** Customer Apps tab contract: live controls and a closed wiki catalog. */
class AppsCatalogTest extends TestCase
{
    public function testGuivRequiresStayInsideDeliveredSet(): void
    {
        $guiv = array('welcome', 'stats', 'scriptsInc', 'userMediaStackPanel', 'qbittorrent',
            'deluge', 'rclone', 'rtorrentRestart', 'lighttpdRestart');
        foreach ($guiv as $name) {
            $source = $this->pmssReadRepoFile('etc/skel/www/'.$name.'.php');
            $tokens = token_get_all($source);
            foreach ($tokens as $index => $token) {
                if (!is_array($token) || !in_array($token[0], array(T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE), true)) continue;
                $expression = '';
                for ($next = $index + 1; isset($tokens[$next]) && $tokens[$next] !== ';'; $next++) {
                    $expression .= is_array($tokens[$next]) ? $tokens[$next][1] : $tokens[$next];
                }
                if (preg_match('/__DIR__\s*\.\s*[\'\"]\/([^\'\"]+\.php)[\'\"]/', $expression, $match)) {
                    $this->assertTrue(in_array(substr($match[1], 0, -4), $guiv, true), $name.' requires update-only '.$match[1]);
                    continue;
                }
                $this->assertTrue(strpos($expression, '$path') !== false &&
                    ($name === 'scriptsInc' && strpos($source, 'is_file($path)') !== false
                        || $name === 'welcome' && strpos($source, "file_exists(\$path = '/etc/seedbox/config/network')") !== false),
                    $name.' has an unguarded dynamic include: '.$expression);
            }
        }
        $this->assertStringContainsString("pmssWelcomeRequireLocalHelper('mediaStackRecoveryCommand.php')",
            $this->pmssReadRepoFile('etc/skel/www/userMediaStackPanel.php'));
    }

    public function testWelcomeAndAppsShareRowsAndReadOnlyStatus(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $this->pmssEnsureDir($home.'/.config/jellyfin', 0700);
            $this->pmssWriteFile($home.'/.jellyfinDisable', '');
            $apps = $render('apps.php', ['minBytes' => 4000])['stdout'];
            $welcome = $render('welcome.php', ['minBytes' => 10000])['stdout'];
            preg_match('/<section class="pmss-apps-section".*?<\/section>/s', $apps, $appsSection);
            preg_match('/<section class="pmss-apps-section".*?<\/section>/s', $welcome, $welcomeSection);
            $this->assertTrue(isset($appsSection[0], $welcomeSection[0]));
            $this->assertSame(str_replace('apps.php?status=1', 'welcome.php?status=1', $appsSection[0]), $welcomeSection[0]);
            $this->assertSame(1, substr_count($welcomeSection[0], 'Restart all my services'));

            $appsStatus = json_decode($render('apps.php', ['query' => 'status=1', 'minBytes' => 100])['stdout'], true);
            $welcomeStatus = json_decode($render('welcome.php', ['query' => 'status=1', 'minBytes' => 100])['stdout'], true);
            $this->assertTrue(is_array($appsStatus) && is_array($welcomeStatus));
            $this->assertSame($appsStatus, $welcomeStatus);
            $this->assertSame('off', $appsStatus['apps']['jellyfin']['state']);
            $this->assertSame(true, $appsStatus['mediaAppControl']);
        });
    }

    public function testHealedWelcomeWorksBeforeUpdateOnlyAppsFilesArrive(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $this->pmssEnsureDir($home.'/.config/jellyfin', 0700);
            $this->pmssWriteFile($home.'/install-media-stack.sh', "#!/bin/sh\nexit 0\n");
            $this->pmssWriteFile($home.'/.bashrc.custom', "# media aliases\n");
            foreach (array('appsRuntime.php', 'mediaStack.php', 'mediaStackRecoveryCommand.php', 'apps.php') as $file) {
                $this->assertTrue(unlink($home.'/www/'.$file));
            }
            $html = $render('welcome.php', ['minBytes' => 10000])['stdout'];
            $this->assertStringContainsString('Your apps', $html);
            $this->assertStringContainsString('Jellyfin', $html);
            $this->assertStringNotContainsString('data-action="stop"', $html);
            $this->assertStringNotContainsString('data-action="log"', $html);
            $status = json_decode($render('welcome.php', ['query' => 'status=1', 'minBytes' => 100])['stdout'], true);
            $this->assertSame(false, $status['mediaAppControl']);
            $this->assertSame(false, $status['canRestart']);
        });
    }

    public function testMovedOperationsExistOnlyInSharedHelper(): void
    {
        $shared = $this->pmssReadRepoFile('etc/skel/www/scriptsInc.php');
        foreach (array('pmssCustomerAppsStatusRead', 'pmssCustomerAppRowHtmlBuild',
            'pmssCustomerAppsSectionHtmlBuild', 'pmssCustomerAppsStatusJsonEmit', 'pmssAppsRestartAll') as $name) {
            $this->assertStringContainsString('function '.$name.'(', $shared);
        }
        foreach (array('welcome.php', 'apps.php') as $page) {
            $source = $this->pmssReadRepoFile('etc/skel/www/'.$page);
            foreach (array('pmssWelcomeManagedAppsHtmlBuild', 'pmssWelcomeServiceRestartActionsBuild',
                'pmssAppsLiveRowBuild', 'pmssAppsMediaRowsBuild', 'pmssAppsAct', 'pmssAppsRestartAll') as $name) {
                $this->assertStringNotContainsString('function '.$name.'(', $source);
            }
        }
    }

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
        $wiki = substr($html, strpos($html, 'id="pmss-more-apps"'), strpos($html, 'More from LinuxServer.io') - strpos($html, 'id="pmss-more-apps"'));
        preg_match_all('/href="([^"]+)"[^>]*>Setup guide &#8599;<\/a>/', $wiki, $matches);
        $this->assertSame(39, count($matches[1]));
        $allowed = array_values(\pmssAppsAllowedUrlsRead());
        foreach ($matches[1] as $href) {
            $this->assertTrue(in_array(html_entity_decode($href, ENT_QUOTES, 'UTF-8'), $allowed, true), $href);
        }
    }

    public function testLsioSnapshotIsFilteredAndHasSafeGuideLinks(): void
    {
        $catalog = require dirname(__DIR__, 4).'/etc/skel/www/appsLsioCatalog.php';
        $this->assertTrue(count($catalog) > 100);
        $names = array_column($catalog, 'name');
        $this->assertTrue(in_array('homeassistant', $names, true));
        $this->assertTrue(in_array('kali-linux', $names, true));
        $this->assertFalse(in_array('oscam', $names, true) || in_array('jellyfin', $names, true));
        foreach (array('boinc', 'foldingathome', 'pwndrop') as $excluded) $this->assertFalse(in_array($excluded, $names, true), $excluded);
        foreach (\PMSS_LSIO_EXCLUDED as $excluded) $this->assertFalse(in_array($excluded, $names, true), $excluded);
        $this->assertTrue(in_array('thelounge', $names, true));
        foreach ($catalog as $app) {
            $this->assertTrue(strpos($app['guide'], 'https://docs.linuxserver.io/images/docker-') === 0);
            $this->assertTrue(preg_match_all('/./us', $app['description']) <= 120);
            $this->assertSame('lscr.io/linuxserver/'.$app['name'], $app['image']);
        }
        $this->assertSame(count($names), count(array_unique($names)));
        $fixture = array(
            array('name' => 'blender', 'description' => '[Blender] is useful. More text.', 'category' => 'Art', 'stable' => true, 'deprecated' => false),
            array('name' => 'jellyfin', 'description' => 'Already provided.', 'category' => 'Media', 'stable' => true, 'deprecated' => false),
            array('name' => 'old', 'description' => 'Old.', 'category' => 'Other', 'stable' => true, 'deprecated' => true),
            array('name' => 'unstable', 'description' => 'Unstable.', 'category' => 'Other', 'stable' => false, 'deprecated' => false),
        );
        $this->assertSame(array(array('name' => 'blender', 'title' => 'Blender', 'description' => 'Blender is useful.',
            'category' => 'Art', 'image' => 'lscr.io/linuxserver/blender',
            'guide' => 'https://docs.linuxserver.io/images/docker-blender/')), \pmssLsioCatalogBuild($fixture));
    }

    public function testMoreAppsRendersOpenWithLsioRowsAndOneSearch(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('apps.php', [], ['minBytes' => 4000]);
        $this->assertStringContainsString('<h2>Find more apps</h2>', $html);
        $this->assertStringNotContainsString('<details>', $html);
        $this->assertStringContainsString('Guides on the Pulsed Media wiki', $html);
        $catalog = require dirname(__DIR__, 4).'/etc/skel/www/appsLsioCatalog.php';
        $this->assertStringContainsString('More from LinuxServer.io ('.count($catalog).')', $html);
        $lsio = substr($html, strpos($html, 'id="pmss-lsio-apps"'));
        $this->assertStringContainsString('data-search="thelounge"', $lsio);
        $this->assertStringContainsString('https://docs.linuxserver.io/images/docker-thelounge/', $lsio);
        $this->assertStringContainsString('https://docs.linuxserver.io/images/docker-blender/', $html);
        $this->assertSame(count($catalog), substr_count($html, 'data-search="'));
        foreach ($catalog as $app) $this->assertStringContainsString('data-search="'.$app['name'].'"', $html);
        $this->assertSame(1, substr_count($html, 'id="pmss-apps-search"'));
        $this->assertStringContainsString('127.0.0.1:8080:8080', $html);
        $this->assertStringContainsString("#pmss-more-apps .mrow, #pmss-lsio-apps .mrow", $html);
        $this->assertStringContainsString("query.replace(/[\\s-]/g, '')", $html);
        $this->assertStringContainsString("search.indexOf(query) !== -1 || search.replace(/[\\s-]/g, '').indexOf(compactQuery) !== -1", $html);
        $this->assertStringContainsString("row.attr('data-search')", $html);
    }

    public function testMissingLsioCatalogLeavesWikiGuidesAvailable(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            @unlink($home.'/www/appsLsioCatalog.php');
            $html = $render('apps.php', ['minBytes' => 4000])['stdout'];
            $this->assertStringContainsString('Guides on the Pulsed Media wiki', $html);
            $this->assertStringNotContainsString('More from LinuxServer.io', $html);
            $this->assertStringContainsString('Find more apps', $html);
        });
    }

    public function testRestartAllIsInResponsivePageHeader(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('apps.php', [], ['minBytes' => 4000]);
        $this->assertSame(1, substr_count($html, '>Restart all my services</button>'));
        $header = substr($html, strpos($html, '<div class="apps-header">'), 220);
        $this->assertStringContainsString('<h2>Your apps</h2><button type="button" class="b" onclick="pmssAppsRestartAll(this)">Restart all my services</button>', $header);
        $this->assertTrue(strpos($html, 'Restart all my services</button>') < strpos($html, '<div class="group">Media Stack</div>'));
        $this->assertStringContainsString('.apps-header{align-items:flex-start;flex-direction:column}', $html);
    }

    public function testRunningInstallerHidesPartialMediaAppControls(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $this->pmssEnsureDir($home.'/.config/jellyfin', 0700);
            $this->pmssWriteFile($home.'/.install-media-stack-web.pid', (string) getmypid());
            $html = $render('apps.php', ['minBytes' => 4000])['stdout'];
            $media = substr($html, strpos($html, 'id="pmss-media-apps"'));
            $media = substr($media, 0, strpos($media, '<div class="group">Torrent clients</div>'));
            $this->assertStringContainsString('data-installing="1"', $media);
            $this->assertSame(1, substr_count($media, 'class="row"'));
            $this->assertStringContainsString('Installing the media stack…', $media);
            $this->assertStringContainsString('Media stack install is running.', $media);
            $this->assertStringNotContainsString('data-app=', $media);
            $this->assertStringNotContainsString('data-action=', $media);
            $this->assertStringNotContainsString('id="pmss-start-all"', $media);
            $json = $render('apps.php', ['query' => 'status=1', 'minBytes' => 100])['stdout'];
            $status = json_decode($json, true);
            $this->assertTrue($status['poll']);
            $this->assertStringContainsString('Installing the media stack…', $status['mediaHtml']);
            $this->assertFalse(isset($status['rows']['jellyfin']));
            $this->assertStringContainsString('payload.poll ? 5000 : 10000', $html);
        });
    }

    public function testAppsSectionRowsKeepLaterGroupsNested(): void
    {
        $status = array('installed' => false, 'poll' => false, 'message' => '',
            'canStart' => true, 'canRestart' => false, 'mediaAppControl' => true,
            'apps' => array(
                'rtorrent' => array('state' => 'running', 'kind' => 'rtorrent', 'url' => ''),
                'qBittorrent' => array('state' => 'off', 'kind' => 'managed', 'url' => ''),
                'Deluge' => array('state' => 'not-running', 'kind' => 'managed', 'url' => ''),
                'rclone' => array('state' => 'running', 'kind' => 'managed', 'url' => ''),
                'lighttpd' => array('state' => 'running', 'kind' => 'web', 'url' => ''),
            ));
        foreach (array('not installed', 'installing', 'installed') as $state) {
            $status['poll'] = $state === 'installing';
            $status['installed'] = $state === 'installed';
            if ($status['installed']) {
                $status['apps']['jellyfin'] = array('state' => 'not-running', 'kind' => 'media', 'url' => '');
            }
            $html = \pmssCustomerAppsSectionHtmlBuild($status);
            $this->assertSame(substr_count($html, '<div'), substr_count($html, '</div>'), $state.' div balance');

            $document = new \DOMDocument();
            $this->assertTrue(@$document->loadHTML($html), $state.' HTML parse');
            $xpath = new \DOMXPath($document);
            $nested = $xpath->query('//*[@id="pmss-torrent-apps"]/ancestor::section[contains(concat(" ", normalize-space(@class), " "), " pmss-apps-section ")]');
            $this->assertSame(1, $nested->length, $state.' Torrent clients inside Apps section');
        }
    }

    public function testFailedInstallRestoresMessageAndInstallButton(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $this->pmssWriteFile($home.'/.install-media-stack.log', "[ERR ] Download failed\n");
            $html = $render('apps.php', ['minBytes' => 4000])['stdout'];
            $this->assertStringContainsString('A previous web install stopped before completion.', $html);
            $this->assertStringContainsString('>Install Media Stack</button>', $html);
            $this->assertStringNotContainsString('Installing the media stack…', $html);
        });
    }

    public function testRenderedHandlersHaveNoEscapedQuotesAndWebServerIsRunning(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            foreach (array(false, true) as $installed) {
                if ($installed) $this->pmssEnsureDir($home.'/.config/jellyfin', 0700);
                $html = $render('apps.php', ['minBytes' => 4000])['stdout'];
                preg_match_all('/\bon[a-z]+\s*=\s*(["\'])(.*?)\1/is', $html, $handlers);
                $this->assertTrue(count($handlers[2]) > 0);
                foreach ($handlers[2] as $handler) $this->assertStringNotContainsString("\\'", $handler);
                $webRow = substr($html, strpos($html, 'data-app="lighttpd"'), 500);
                $this->assertStringContainsString('<span class="pill p-run">Running</span>', $webRow);
                $this->assertSame(1, substr_count($webRow, 'data-action="restart"'));
                if ($installed) $this->assertStringContainsString('id="pmss-start-all" data-bulk-action="start-stopped"', $html);
            }
        });
        $shared = $this->pmssReadRepoFile('etc/skel/www/scriptsInc.php');
        $this->assertStringContainsString('data-bulk-action="start-stopped"', $shared);
        $this->assertStringContainsString("$(document).on('click', '#pmss-start-all[data-bulk-action]'", $shared);
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
        $this->assertStringContainsString('rtorrentRestart.php?action=', $shared);
        foreach (array('pmssMediaStackStart', 'pmssMediaStackSecureApp') as $function) {
            $this->assertStringContainsString('function '.$function.'(', $shared);
        }
        foreach (array('mediaStack.php?action=status', 'mediaStack.php?action=', 'mediaStack.php') as $route) {
            $this->assertStringContainsString($route, $shared);
        }
        $this->assertStringContainsString("'endpoint' => 'qbittorrent.php'", $this->pmssReadRepoFile('etc/skel/www/scriptsInc.php'));
        $this->assertStringContainsString('pmssActionScriptJs()', $this->pmssReadRepoFile('etc/skel/www/welcome.php'));
        $this->assertStringContainsString("'www/appsRuntime.php'", $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php'));
        $this->assertStringContainsString("'www/appsLsioCatalog.php'", $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php'));
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
            $this->assertSame('running', $status['apps']['lighttpd']['state']);
            $this->assertStringContainsString('<span class="pill p-run">Running</span>', $status['rows']['lighttpd']);
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

    public function testStatsShowsUpdatingNoticeBeforeUpdateOnlyHelperArrives(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render, string $runRoot): void {
            $this->assertTrue(unlink($home.'/www/statsHelpers.php'));
            file_put_contents($runRoot.'/php-cli-bootstrap.php',
                "register_shutdown_function(function () { echo ' HTTP='.(http_response_code() ?: 200); });\n", FILE_APPEND);
            $result = $render('stats.php', array('minBytes' => 80));
            $this->assertSame(0, $result['rc']);
            $this->assertSame('', $result['stderr']);
            $this->assertSame('The Info page is being updated on this server. Reload it in a few minutes. HTTP=200',
                $result['stdout']);
        });
    }

    public function testAppsFeedsAnswerUpdatingWhenGuivHelperIsOld(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render, string $runRoot): void {
            $this->pmssWriteFile($home.'/www/scriptsInc.php', "<?php\n");
            file_put_contents($runRoot.'/php-cli-bootstrap.php',
                "register_shutdown_function(function () { echo ' HTTP='.(http_response_code() ?: 200); });\n", FILE_APPEND);
            foreach (array('status=1', 'log=sonarr') as $query) {
                $result = $render('apps.php', array('query' => $query, 'minBytes' => 100));
                $this->assertSame(0, $result['rc']);
                $this->assertSame('', $result['stderr']);
                $this->assertSame('{"updating":true,"message":"The Apps page is being updated on this server. Reload it in a few minutes."} HTTP=503',
                    $result['stdout']);
            }
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
