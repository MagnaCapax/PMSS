<?php
namespace PMSS\Tests;

/**
 * Render-level guard for the user-panel top-frame navigation contract (ADR 0021).
 *
 * The existing customer-panel-render-harness renders welcome/info/stats but NOT
 * index.php, the tab host where the navigation contract actually lives. This test
 * renders index.php under CLI PHP with a synthetic customer home and asserts the
 * ADR 0021 invariants directly on the produced HTML.
 *
 * Origin: REFACTOR_WITHOUT_RENDER_VERIFICATION (catalog #237). A months-long
 * structure-only refactor campaign on etc/skel/www/* shipped without ever rendering
 * the panel; the top frame silently regressed fleet-wide (wiki opened a new window,
 * disabled-app tabs returned 503, layout broke). This test fails CI when any ADR 0021
 * invariant breaks, so the regression cannot ship unseen again.
 */
class UserPanelTopFrameContractTest extends TestCase
{
    /**
     * Render etc/skel/www/index.php in a throwaway customer home.
     *
     * @param string[] $homeFlags  per-user enable flags to touch in the home dir (e.g. '.qbittorrentEnable')
     * @param string[] $configDirs config dirs to create in the home dir (e.g. '.config/qBittorrent')
     */
    private function renderIndex(array $homeFlags = [], array $configDirs = []): string
    {
        $home = $this->pmssMakeTempDir('panel-home-');
        $www = $home.'/www';
        $this->assertTrue(@mkdir($www, 0755, true) || is_dir($www), 'temp www dir must exist');

        $src = $this->pmssRepoPath('etc/skel/www');
        foreach (['index.php', 'pmssTabs.js', 'jquery.tabs.css', 'welcome.php'] as $file) {
            if (is_file($src.'/'.$file)) {
                copy($src.'/'.$file, $www.'/'.$file);
            }
        }
        foreach ($configDirs as $dir) {
            @mkdir($home.'/'.$dir, 0755, true);
        }
        foreach ($homeFlags as $flag) {
            touch($home.'/'.$flag);
        }

        $command = 'cd '.escapeshellarg($www).' && '.escapeshellarg(PHP_BINARY).' index.php 2>/dev/null';
        return (string) shell_exec($command);
    }

    /** Return the id of the first in-page tab anchor (the default tab), or '' if none. */
    private function firstTabId(string $html): string
    {
        return preg_match('/<li><a href="#([a-z0-9_-]+)"/i', $html, $m) === 1 ? strtolower($m[1]) : '';
    }

    /** ADR 0021 #1 — no top-frame entry opens a new window. */
    public function testNavHasNoNewWindowTargets(): void
    {
        $html = $this->renderIndex();
        $this->assertStringNotContainsString('target="_blank"', $html,
            'ADR 0021 #1: top-frame navigation must use in-page tabs, never target=_blank / new windows');
    }

    /** ADR 0021 #1 — wiki specifically is an in-page tab, not a new-window link. */
    public function testWikiIsInPageTab(): void
    {
        $html = $this->renderIndex();
        $this->assertStringContainsString("loadFrame('wiki'", $html,
            'ADR 0021 #1: wiki must render as an in-page tab (loadFrame), not a new-window link');
    }

    /** ADR 0021 #3 — the panel opens on welcome by default. */
    public function testDefaultTabIsWelcome(): void
    {
        $html = $this->renderIndex();
        $this->assertSame('welcome', $this->firstTabId($html),
            'ADR 0021 #3: the default (first) top-frame tab must be welcome, never a feature tab that may 503');
    }

    /** ADR 0021 #2 — a disabled app (config dir present, enable flag absent) must NOT surface a tab. */
    public function testDisabledAppHasNoTab(): void
    {
        $html = $this->renderIndex([], ['.config/qBittorrent']);
        $this->assertStringNotContainsString("loadFrame('qbittorrent'", $html,
            'ADR 0021 #2: a disabled app (leftover config dir, no enable flag) must not surface a tab — it would 503 on click');
    }

    /** ADR 0021 #2 — an enabled app surfaces its tab. */
    public function testEnabledAppShowsTab(): void
    {
        $html = $this->renderIndex(['.qbittorrentEnable'], ['.config/qBittorrent']);
        $this->assertStringContainsString("loadFrame('qbittorrent'", $html,
            'ADR 0021 #2: an enabled app (enable flag present) must surface its top-frame tab');
    }

    /** The panel references its local tab CSS asset (regression guard for missing-asset drift). */
    public function testTabCssAssetReferenced(): void
    {
        $html = $this->renderIndex();
        $this->assertStringContainsString('jquery.tabs.css', $html,
            'panel must reference its local tab CSS asset (jquery.tabs.css)');
    }
}
