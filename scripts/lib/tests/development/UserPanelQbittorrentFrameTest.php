<?php
/** Guard the rendered panel's qBittorrent parent-window compatibility contract. */
namespace PMSS\Tests;

class UserPanelQbittorrentFrameTest extends TestCase
{
    /** The bridge must be available before plugin initialization or hash navigation can load an app. */
    public function testBridgePrecedesTabInitialization(): void
    {
        $html = $this->renderPanel();
        foreach (["$('#tabs').tabs(", 'if (!fromHash())'] as $initialization) {
            $this->assertOrderedStrings(['function pmssQbtWindow()', $initialization], $html);
        }
    }

    /** Namespace identity preserves stored colour/date preferences and every upstream dialog API. */
    public function testNamespaceForwardsLiveFrameDataInsteadOfStubs(): void
    {
        $this->assertStringContainsAndOmitsStrings([
            "if (!('qBittorrent' in window)) Object.defineProperty(window, 'qBittorrent'",
            'get: function () { var w = pmssQbtWindow(); return w ? w.qBittorrent : undefined; }',
        ], ['ClientData: {', 'ClientData = {'], $this->renderPanel());
    }

    /** The speed-limit dialog must refresh through the same framed window that owns it. */
    public function testRefreshCallbackForwardsToFrameWithoutReplacingExistingApi(): void
    {
        $this->assertStringContainsAllStrings([
            'window.updateMainData = window.updateMainData || function ()',
            'var w = pmssQbtWindow(); if (w && w.updateMainData) w.updateMainData();',
        ], $this->renderPanel());
    }

    /** Custom cross-origin tabs cannot prevent discovery, and remote/local key casing is irrelevant. */
    public function testFrameDiscoveryUsesContentAndSkipsUnreadableSiblings(): void
    {
        $html = $this->renderPanel();
        $start = strpos($html, 'function pmssQbtWindow()');
        $end = strpos($html, '// Forward the live namespace', $start === false ? 0 : $start);
        $this->assertTrue($start !== false && $end !== false && $end > $start);
        $this->assertStringContainsAndOmitsStrings([
            "document.getElementsByTagName('iframe')",
            'try {', 'frames[i].contentWindow',
            'w && w !== window && w.qBittorrent && w.qBittorrent.ClientData',
            'catch (e) {}', 'return null;',
        ], ['getElementById(', 'querySelector('], substr($html, $start, $end - $start));
    }

    /** Mocha receives the viewport rectangle without replacing an existing coordinates API. */
    public function testUnderlayCoordinatesCoverViewport(): void
    {
        $this->assertStringContainsAllStrings([
            'window.getCoordinates = window.getCoordinates || function ()',
            'top: 0, left: 0, width: window.innerWidth, height: window.innerHeight',
            'right: window.innerWidth, bottom: window.innerHeight',
        ], $this->renderPanel());
    }

    /** The compatibility bridge must retain the existing enabled-app, in-page navigation. */
    public function testQbittorrentTabStillLoadsInsidePanel(): void
    {
        $html = $this->renderPanel();
        $this->assertStringContainsAllStrings([
            'href="#qbittorrent"', "loadFrame('qbittorrent', 'qbittorrent/')",
            'function pmssQbtWindow()',
        ], $html);
        $this->assertSame(0, preg_match('/<li[^>]*>\s*<a\b[^>]*\btarget\s*=\s*[\'"]?_blank/i', $html));
    }

    /** Render a synthetic account through the same entrypoint that serves customer navigation. */
    private function renderPanel(): string
    {
        $home = $this->pmssMakeUserWebHome('pmss-qbt-frame-', 'fixtur01');
        $this->pmssEnsureDir($home.'/.config/qBittorrent');
        $this->pmssWriteFile($home.'/.qbittorrentEnable', '');
        return $this->pmssRenderUserPanelIndexFromHome($home, ['PMSS_DISABLE_REMOTE_FRAMES' => '1']);
    }
}
