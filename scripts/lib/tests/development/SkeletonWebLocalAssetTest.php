<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SkeletonWebLocalAssetTest extends TestCase
{
    public function testWelcomeAndInfoUseBundledScreenStylesheet(): void
    {
        foreach (['etc/skel/www/welcome.php', 'etc/skel/www/info.php'] as $path) {
            $this->pmssAssertRepoFileContainsString($path, 'href="screen.css"', $path.' should load the bundled stylesheet.');
            $this->pmssAssertRepoFileNotContainsString($path, 'static.pulsedmedia.com/wc/css/screen.css', $path.' should not depend on the retired static stylesheet host.');
        }
    }

    public function testBundledScreenStylesheetKeepsWelcomePanelCompact(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/skel/www/screen.css',
            [
                'font-size: 13px;',
                'line-height: 1.3;',
                'padding: 6px;',
                'gap: 8px;',
                'font-size: 1.15rem;',
                'font-size: 0.8rem;',
                'height: 12px;',
            ],
            'Missing compact welcome stylesheet rule: '
        );

        $this->pmssAssertRepoFileNotContainsStrings(
            'etc/skel/www/screen.css',
            [
                'padding: 24px 16px;',
                'gap: 24px;',
                'font-size: 2rem;',
                'font-size: 1.05rem;',
                'padding: 9px 14px;',
            ],
            'Large welcome stylesheet rule should not return: '
        );
    }

    public function testIndexLocalFallbackUsesBundledTabsAssets(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/skel/www/index.php',
            [
                '<script src="pmssTabs.js"></script>',
                '<link rel="stylesheet" href="jquery.tabs.css"',
            ],
            'Missing local tabs asset reference: '
        );

        $this->pmssAssertRepoFileNotContainsStrings('etc/skel/www/index.php', [
            'static.pulsedmedia.com/jquery.tabs.pack.js',
            'static.pulsedmedia.com/jquery.tabs.css',
        ], 'index.php should not depend on remote tabs assets: ');
    }

    public function testLocalTabsHelperAddsNavigationClass(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'etc/skel/www/index.php',
            '<ul class="tabs-nav">',
            'index.php must emit styled tab navigation even when JavaScript fails to load.'
        );

        $this->pmssAssertRepoFileContainsString(
            'etc/skel/www/pmssTabs.js',
            "container.find('> ul').addClass('tabs-nav');",
            'pmssTabs.js must add the tabs-nav class required by jquery.tabs.css.'
        );
    }

    public function testIndexTabsOverrideLegacySpriteGeometry(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/skel/www/index.php',
            [
                'top: 34px;',
                'font-size: 14px;',
                'padding: 6px 14px;',
                'width: auto;',
                'height: auto;',
                'min-width: 0;',
                'min-height: 0;',
                'background-image: none;',
                'var offsetHeight = -34;',
            ],
            'Missing tab geometry override: '
        );

        $this->pmssAssertRepoFileNotContainsStrings(
            'etc/skel/www/jquery.tabs.css',
            [
                'background: url(tab.png) no-repeat;',
                'background-position:',
                'width: 64px;',
                'min-width: 64px;',
                'height: 18px;',
                'min-height: 18px;',
                'padding-top: 6px;',
                'padding-right: 0;',
            ],
            'Legacy sprite tab geometry should not constrain flex tabs: '
        );
    }

    public function testLighttpdTemplateEnablesRemoteFrames(): void
    {
        // Reverted #601 per operator directive 2026-06-03: remote guiFrames is the
        // PRIMARY on-load GUI auto-update path, local frames are the FAILOVER. The
        // template must NOT set PMSS_DISABLE_REMOTE_FRAMES, so the per-user php-cgi
        // environment leaves remote frames enabled. (Safe re-enable precondition:
        // web4 serves current files via the daily guiv sync cron — see
        // /home/billing/scripts/sync-guiv-from-pmss.sh.)
        $this->pmssAssertRepoFileNotContainsStrings(
            'etc/seedbox/config/template.lighttpd',
            ['PMSS_DISABLE_REMOTE_FRAMES'],
            'lighttpd template must NOT disable remote frames (remote is primary, local is failover): '
        );
    }

    public function testUserFilesystemSyncIncludesBundledGuiAssets(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/lib/update/users/filesystem.php',
            [
                "'www/scriptsInc.php',",
                "'www/deluge.php',",
                "'www/error-503.html',",
                "'www/filemanager.php',",
                "'www/info.php',",
                "'www/index.php',",
                "'www/jquery.tabs.css',",
                "'www/mediaStack.php',",
                "'www/pmssTabs.js',",
                "'www/qbittorrent.php',",
                "'www/rclone.php',",
                "'www/screen.css',",
                "'www/stats.php',",
                "'www/statsHelpers.php',",
                "'www/welcome.php',",
            ],
            'Missing skeleton sync asset entry: '
        );
    }

    public function testWelcomeStartupCallsStayInsideWelcomeAndScriptsInc(): void
    {
        $welcome = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $scriptsInc = $this->pmssReadRepoFile('etc/skel/www/scriptsInc.php');

        $startup = $this->welcomeStartupPhpBlock($welcome);
        preg_match_all('/\b(pmss[A-Za-z0-9_]*)\s*\(/', $startup, $callMatches);
        $calls = array_unique($callMatches[1]);
        sort($calls);

        $definitions = array_fill_keys(array_merge(
            $this->phpFunctionNames($welcome),
            $this->phpFunctionNames($scriptsInc)
        ), true);
        $missing = array();
        foreach ($calls as $function) {
            if (!isset($definitions[$function])) {
                $missing[] = $function;
            }
        }

        $this->assertSame(
            array(),
            $missing,
            'welcome.php startup calls must be defined by welcome.php or its required scriptsInc.php'
        );
    }

    public function testWelcomeAndScriptsIncStayOrderedInUserDeliveryManifest(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/lib/update/users/filesystem.php',
            [
                "'www/scriptsInc.php',",
                "'www/welcome.php',",
            ],
            'Missing panel delivery-set manifest entry: ',
            'scriptsInc.php must be delivered before welcome.php: '
        );
    }

    /**
     * Self-containment safeguard (GH#423 class): every RELATIVE .css/.js asset the
     * customer panel references MUST exist in skel AND be listed in the per-user
     * update manifest, so update.php delivers it. References are extracted
     * DYNAMICALLY (not a hardcoded list) so future additions are covered without
     * editing this test. Origin: 2026-06-03 — index.php referenced bundled
     * jquery.tabs.css/pmssTabs.js (GH#423) but the remote heal deleted them and only
     * update.php delivered them; a referenced-but-undeliverable asset 404s and
     * breaks/unstyles the dashboard. This test fails the build if any panel file
     * references a local asset that skel or the manifest does not provide.
     */
    public function testReferencedLocalAssetsAreDeliverableViaManifest(): void
    {
        $panelFiles = ['etc/skel/www/index.php', 'etc/skel/www/welcome.php', 'etc/skel/www/info.php'];
        $manifest = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');
        $repoRoot = $this->pmssRepoRoot();
        $missing = [];
        foreach ($panelFiles as $panel) {
            $src = $this->pmssReadRepoFile($panel);
            if (!preg_match_all('/(?:src|href)="(?!https?:)(?!\/)([^"?]+\.(?:css|js))(?:\?[^"]*)?"/', $src, $matches)) {
                continue;
            }
            foreach (array_unique($matches[1]) as $asset) {
                if (!is_file($repoRoot.'/etc/skel/www/'.$asset)) {
                    $missing[] = $panel.' references "'.$asset.'" but it is missing from etc/skel/www/';
                } elseif (strpos($manifest, "'www/".$asset."'") === false) {
                    $missing[] = $panel.' references "'.$asset.'" but "www/'.$asset.'" is NOT in the update.php manifest (filesystem.php) — update.php will not deliver it';
                }
            }
        }
        $this->assertSame([], $missing, "Customer-panel local asset(s) referenced but not deliverable:\n".implode("\n", $missing));
    }

    private function welcomeStartupPhpBlock(string $welcome): string
    {
        $start = strpos($welcome, "require_once __DIR__.'/scriptsInc.php';");
        $end = strpos($welcome, '?>', $start === false ? 0 : $start);
        $this->assertTrue($start !== false && $end !== false && $end > $start, 'welcome.php startup block markers changed');

        return substr($welcome, $start, $end - $start);
    }

    private function phpFunctionNames(string $source): array
    {
        preg_match_all('/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches);
        return array_unique($matches[1]);
    }
}
