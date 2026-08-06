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
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
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
            [
                'padding: 24px 16px;',
                'gap: 24px;',
                'font-size: 2rem;',
                'font-size: 1.05rem;',
                'padding: 9px 14px;',
            ]
        );
    }

    public function testWelcomeBonusBannerStylesStayWithGuivDeliveredPage(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'etc/skel/www/welcome.php',
            [
                '.pmss-bonus-banner {',
                'flex: 0 0 100%;',
                '.pmss-bonus-banner strong {',
                'font-size: 2.4rem;',
            ],
            []
        );

        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'etc/skel/www/screen.css',
            [],
            [
                '.pmss-bonus-banner {',
                '.pmss-bonus-banner strong {',
            ]
        );
    }

    /**
     * Keep full-row portfoliobox children self-contained in their guiv page.
     *
     * The guiv heal delivers PHP files more often than update.php refreshes
     * screen.css. Discovering direct child classes keeps new panel banners
     * covered without maintaining a second hardcoded class list.
     */
    public function testPortfolioboxDirectChildStylesStayWithGuivPage(): void
    {
        $candidates = [];
        foreach (glob($this->pmssRepoPath('etc/skel/www/*.php')) ?: [] as $path) {
            $source = $this->pmssReadRepoFile('etc/skel/www/'.basename($path));
            $classes = $this->customerPanelPortfolioboxDirectChildClasses($source);
            if ($classes !== []) {
                $candidates['etc/skel/www/'.basename($path)] = [$source, $classes];
            }
        }

        $this->assertTrue($candidates !== [], 'Expected a portfoliobox panel source with a pmss-* child.');
        $screenCss = $this->pmssReadRepoFile('etc/skel/www/screen.css');
        foreach ($candidates as $path => [$source, $classes]) {
            preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $source, $styleMatches);
            $inlineCss = implode("\n", $styleMatches[1]);
            foreach ($classes as $class) {
                $rulePattern = '/\.'.preg_quote($class, '/').'\s*\{[^}]*\bflex\s*:\s*0\s+0\s+100%\s*;/s';
                $this->assertMatches(
                    $rulePattern,
                    $inlineCss,
                    $path.' must keep .'.$class.' full-row styling inline with its guiv-delivered markup.'
                );
                $this->assertStringNotContainsString(
                    '.'.$class,
                    $screenCss,
                    '.'.$class.' must not depend on the slower base-delivered screen.css.'
                );
            }
        }
    }

    public function testIndexLocalFallbackUsesBundledTabsAssets(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'etc/skel/www/index.php',
            [
                '<script src="pmssTabs.js"></script>',
                '<link rel="stylesheet" href="jquery.tabs.css"',
            ],
            [
                'static.pulsedmedia.com/jquery.tabs.pack.js',
                'static.pulsedmedia.com/jquery.tabs.css',
            ]
        );
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
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
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
            []
        );

        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'etc/skel/www/jquery.tabs.css',
            [],
            [
                'background: url(tab.png) no-repeat;',
                'background-position:',
                'width: 64px;',
                'min-width: 64px;',
                'height: 18px;',
                'min-height: 18px;',
                'padding-top: 6px;',
                'padding-right: 0;',
            ]
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

    /**
     * Guard the inverse of GH#423: every delivered local .css/.js asset should
     * have at least one loader in the customer-panel source tree.
     */
    public function testManifestLocalAssetsAreReferencedByPanelSources(): void
    {
        $referenced = $this->customerPanelReferencedLocalAssets();
        $unreferenced = [];
        foreach ($this->customerPanelManifestLocalAssets() as $asset) {
            if (!isset($referenced[$asset])) {
                $unreferenced[] = 'www/'.$asset;
            }
        }

        $this->assertSame(
            [],
            $unreferenced,
            "Customer-panel .css/.js manifest asset(s) have no local loader:\n".implode("\n", $unreferenced)
        );
    }

    /** @return array<int, string> */
    private function customerPanelManifestLocalAssets(): array
    {
        $manifest = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');
        preg_match_all("/'www\\/([^']+\\.(?:css|js))'/", $manifest, $matches);
        $assets = array_values(array_unique($matches[1]));
        sort($assets);

        $this->assertTrue($assets !== [], 'Expected customer-panel .css/.js manifest entries');
        return $assets;
    }

    /** @return array<string, bool> */
    private function customerPanelReferencedLocalAssets(): array
    {
        $referenced = [];
        foreach ($this->customerPanelReferenceSourceFiles() as $sourceFile) {
            $source = @file_get_contents($this->pmssRepoPath($sourceFile));
            $this->assertTrue(is_string($source), 'Unable to read '.$sourceFile);
            $baseDir = dirname(substr($sourceFile, strlen('etc/skel/www/')));
            $baseDir = $baseDir === '.' ? '' : $baseDir;

            foreach ($this->customerPanelExtractLocalAssetReferences($source) as $reference) {
                $asset = $this->customerPanelNormalizeAssetReference($baseDir, $reference);
                if ($asset !== null) {
                    $referenced[$asset] = true;
                }
            }
        }

        ksort($referenced);
        return $referenced;
    }

    /** @return array<int, string> */
    private function customerPanelReferenceSourceFiles(): array
    {
        $root = $this->pmssRepoPath('etc/skel/www');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || !preg_match('/\.(?:php|html|css|js)$/i', $file->getFilename())) {
                continue;
            }
            $files[] = 'etc/skel/www/'.str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
        sort($files);

        return $files;
    }

    /** @return array<int, string> */
    private function customerPanelExtractLocalAssetReferences(string $source): array
    {
        $patterns = [
            '/\b(?:src|href)\s*=\s*["\']([^"\']+\.(?:css|js))(?:\?[^"\']*)?["\']/i',
            '/@import\s+(?:url\(\s*)?["\']?([^"\'\)\s]+\.css)(?:\?[^"\'\)\s]*)?["\']?\s*\)?/i',
            '/url\(\s*["\']?([^"\'\)\s]+(?:\.css|\.js))(?:\?[^"\'\)]*)?["\']?\s*\)/i',
        ];

        $references = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $matches);
            foreach ($matches[1] as $reference) {
                $references[] = $reference;
            }
        }

        return array_values(array_unique($references));
    }

    private function customerPanelNormalizeAssetReference(string $baseDir, string $reference): ?string
    {
        $reference = html_entity_decode(trim($reference), ENT_QUOTES, 'UTF-8');
        $withoutQuery = preg_replace('/[?#].*$/', '', $reference);
        $reference = is_string($withoutQuery) ? $withoutQuery : '';
        if ($reference === '' || preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/|\/)/i', $reference)) {
            return null;
        }
        if (!preg_match('/\.(?:css|js)$/i', $reference)) {
            return null;
        }

        $parts = [];
        $path = ($baseDir !== '' ? $baseDir.'/' : '').str_replace('\\', '/', $reference);
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return $parts === [] ? null : implode('/', $parts);
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

    /** @return array<int, string> */
    private function customerPanelPortfolioboxDirectChildClasses(string $source): array
    {
        preg_match_all('/<\/?div\b[^>]*>/i', $source, $tagMatches);
        $depth = 0;
        $classes = [];
        foreach ($tagMatches[0] as $tag) {
            if (preg_match('/^<div\b/i', $tag)) {
                if ($depth === 0 && $this->customerPanelTagHasClass($tag, 'portfoliobox')) {
                    $depth = 1;
                    continue;
                }
                if ($depth === 1) {
                    foreach ($this->customerPanelTagClasses($tag) as $class) {
                        if (strpos($class, 'pmss-') === 0) {
                            $classes[] = $class;
                        }
                    }
                }
                if ($depth > 0) {
                    $depth++;
                }
            } elseif ($depth > 0 && preg_match('/^<\/div\b/i', $tag)) {
                $depth--;
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);
        return $classes;
    }

    private function customerPanelTagHasClass(string $tag, string $expected): bool
    {
        return in_array($expected, $this->customerPanelTagClasses($tag), true);
    }

    /** @return array<int, string> */
    private function customerPanelTagClasses(string $tag): array
    {
        if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $tag, $matches) !== 1) {
            return [];
        }
        return preg_split('/\s+/', trim($matches[2])) ?: [];
    }
}
