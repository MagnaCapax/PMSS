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
    public function testConfigureIsLastAndAppsFallsBackBeforeDelivery(): void
    {
        $html = $this->pmssRenderCopiedUserPanelIndex(array(), array(),
            array('apps.php', 'configure.php', 'configureSettings.php'), array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));
        $this->assertStringContainsString('href="#pmssconfigure"', $html);
        $this->assertStringNotContainsString('href="#apps"', $html);
        $this->assertTrue(strrpos($html, 'href="#pmssconfigure"') > strrpos($html, 'href="#wiki"'));
        $this->assertStringContainsString('aria-label="Configure"', $html);
        $legacy = $this->pmssRenderCopiedUserPanelIndex(array(), array(), array('apps.php'),
            array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));
        $this->assertStringContainsString('href="#apps"', $legacy);
        $this->assertStringNotContainsString('href="#pmssconfigure"', $legacy);
        $partial = $this->pmssRenderCopiedUserPanelIndex(array(), array(), array('apps.php', 'configure.php'),
            array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));
        $this->assertStringContainsString('href="#apps"', $partial);
        $this->assertStringNotContainsString('href="#pmssconfigure"', $partial);
    }

    public function testConfigureStaysLastAfterCustomerFrames(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-configure-tabs-');
        foreach (array('apps.php', 'configure.php', 'configureSettings.php') as $name) {
            $this->assertTrue(copy($this->pmssRepoPath('etc/skel/www/'.$name), $home.'/www/'.$name));
        }
        $this->pmssWriteFile($home.'/.customFrames', "custom0|Guide|Guide|https://example.com\ncustom1|Duplicate|Duplicate|configure.php\n");
        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));
        $this->assertSame(1, substr_count($html, 'href="#pmssconfigure"'));
        $this->assertTrue(strrpos($html, 'href="#pmssconfigure"') > strrpos($html, 'href="#custom0"'));
        $this->assertStringNotContainsString('href="#custom1"', $html);
    }

    /** ADR 0169 — the tab bar is one tablist: Welcome selected, an icon and a plain-text label per tab, Configure at the end. */
    public function testTabBarIsATablistWithIconsAndPlainLabels(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-tab-bar-');
        foreach (array('configure.php', 'configureSettings.php') as $name) {
            $this->assertTrue(copy($this->pmssRepoPath('etc/skel/www/'.$name), $home.'/www/'.$name));
        }
        $this->pmssWriteFile($home.'/.customFrames', "custom0|My guide|<b>Guide</b>|https://example.com\n");
        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));
        $this->assertStringContainsString('<ul class="tabs-nav" role="tablist" aria-label="Panel sections">', $html);
        $this->assertStringContainsString('<li><a href="#welcome" title="Welcome to your seedbox. Basic information" id="pmss-tab-welcome" role="tab" aria-controls="welcome" aria-selected="true" tabindex="0" data-tab="welcome"', $html);
        $this->assertSame(1, substr_count($html, 'aria-selected="true" tabindex="0"'));
        $this->assertSame(substr_count($html, 'role="tab" aria-controls='), substr_count($html, 'aria-selected="false" tabindex="-1"') + 1);
        $this->assertStringContainsString('<use href="#pmss-i-house" xlink:href="#pmss-i-house"></use></svg><span class="pt-lbl">Welcome</span>', $html);
        $this->assertStringContainsString('<span class="pt-lbl">Wiki</span>', $html);
        $this->assertStringContainsString('<li class="pt-end"><a href="#pmssconfigure"', $html);
        $this->assertStringContainsString('#pmss-i-settings"></use></svg><span class="pt-lbl">Configure</span>', $html);
        $this->assertStringNotContainsString('⚙', $html);
        $this->assertStringContainsString('#pmss-i-app-window"></use></svg><span class="pt-lbl">&lt;b&gt;Guide&lt;/b&gt;</span>', $html);
        $this->assertSame(1, substr_count($html, '<symbol id="pmss-i-house"'));
        $this->assertStringContainsString("data.type !== 'pmss:apps-state'", $html);
    }

    /** Return the id of the first in-page tab anchor (the default tab), or '' if none. */
    private function firstTabId(string $html): string
    {
        return preg_match('/<li><a href="#([a-z0-9_-]+)"/i', $html, $m) === 1 ? strtolower($m[1]) : '';
    }

    /** ADR 0021 #1 — no top-frame entry opens a new window. */
    public function testNavHasNoNewWindowTargets(): void
    {
        $html = $this->pmssRenderCopiedUserPanelIndex();
        $this->assertStringNotContainsString('target="_blank"', $html,
            'ADR 0021 #1: top-frame navigation must use in-page tabs, never target=_blank / new windows');
    }

    /** ADR 0021 #1 — wiki specifically is an in-page tab, not a new-window link. */
    public function testWikiIsInPageTab(): void
    {
        $html = $this->pmssRenderCopiedUserPanelIndex();
        $this->assertStringContainsString("loadFrame('wiki'", $html,
            'ADR 0021 #1: wiki must render as an in-page tab (loadFrame), not a new-window link');
    }

    /** ADR 0021 #3 — the panel opens on welcome by default. */
    public function testDefaultTabIsWelcome(): void
    {
        $html = $this->pmssRenderCopiedUserPanelIndex();
        $this->assertSame('welcome', $this->firstTabId($html),
            'ADR 0021 #3: the default (first) top-frame tab must be welcome, never a feature tab that may 503');
    }

    /** ADR 0021 #2 — the console tab requires both launcher and ttyd. */
    public function testConsoleTabShownWhenBackendPresent(): void
    {
        $ttyd = $this->pmssMakeTempFile('pmss-ttyd-');
        $html = $this->pmssRenderCopiedUserPanelIndex([], [], ['console.php'], [
            'PMSS_CONSOLE_TTYD_BIN' => $ttyd,
            'PMSS_DISABLE_REMOTE_FRAMES' => '1',
        ]);
        $this->assertStringContainsString("loadFrame('console'", $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
        $this->assertSame('welcome', $this->firstTabId($html));
    }

    /** ADR 0021 #2 — an undeployed ttyd must not advertise a console tab. */
    public function testConsoleTabHiddenWithoutTtyd(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-ttyd-');
        $html = $this->pmssRenderCopiedUserPanelIndex([], [], ['console.php'], [
            'PMSS_CONSOLE_TTYD_BIN' => $dir.'/missing',
            'PMSS_DISABLE_REMOTE_FRAMES' => '1',
        ]);
        $this->assertStringNotContainsString("loadFrame('console'", $html);
    }

    /** ADR 0021 #2 — the launcher must reach the user tree before the tab appears. */
    public function testConsoleTabHiddenWithoutLauncher(): void
    {
        $ttyd = $this->pmssMakeTempFile('pmss-ttyd-');
        $html = $this->pmssRenderCopiedUserPanelIndex([], [], [], [
            'PMSS_CONSOLE_TTYD_BIN' => $ttyd,
            'PMSS_DISABLE_REMOTE_FRAMES' => '1',
        ]);
        $this->assertStringNotContainsString("loadFrame('console'", $html);
    }

    /** A delivered Apps page gets an in-page tab after the default Welcome tab. */
    public function testAppsTabShownWhenPagePresent(): void
    {
        $html = $this->pmssRenderCopiedUserPanelIndex([], [], ['apps.php'], [
            'PMSS_DISABLE_REMOTE_FRAMES' => '1',
        ]);
        $this->assertStringContainsString("loadFrame('apps'", $html);
        $this->assertSame('welcome', $this->firstTabId($html));
        $this->assertStringNotContainsString('target="_blank"', $html);
    }

    /** An account still awaiting apps.php must not get a broken tab. */
    public function testAppsTabHiddenWithoutPage(): void
    {
        $html = $this->pmssRenderCopiedUserPanelIndex([], [], [], [
            'PMSS_DISABLE_REMOTE_FRAMES' => '1',
        ]);
        $this->assertStringNotContainsString("loadFrame('apps'", $html);
        $this->assertSame('welcome', $this->firstTabId($html));
    }

    /** ADR 0021 #2 — a disabled app (config dir present, enable flag absent) must NOT surface a tab. */
    public function testDisabledAppHasNoTab(): void
    {
        $html = $this->pmssRenderCopiedUserPanelIndex([], ['.config/qBittorrent']);
        $this->assertStringNotContainsString("loadFrame('qbittorrent'", $html,
            'ADR 0021 #2: a disabled app (leftover config dir, no enable flag) must not surface a tab — it would 503 on click');
    }

    /** ADR 0021 #2 — an enabled app surfaces its tab. */
    public function testEnabledAppShowsTab(): void
    {
        $html = $this->pmssRenderCopiedUserPanelIndex(['.qbittorrentEnable'], ['.config/qBittorrent']);
        $this->assertStringContainsString("loadFrame('qbittorrent'", $html,
            'ADR 0021 #2: an enabled app (enable flag present) must surface its top-frame tab');
    }

    /** The panel references its local tab CSS asset (regression guard for missing-asset drift). */
    public function testTabCssAssetReferenced(): void
    {
        $html = $this->pmssRenderCopiedUserPanelIndex();
        $this->assertStringContainsString('jquery.tabs.css', $html,
            'panel must reference its local tab CSS asset (jquery.tabs.css)');
    }
}
