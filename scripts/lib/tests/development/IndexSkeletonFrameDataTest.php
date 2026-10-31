<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsInc.php';

class IndexSkeletonFrameDataTest extends TestCase
{
    public function testOpenClawTabRequiresProxyAndStaysInsidePanel(): void
    {
        $this->loadIndexFrameHelpers();
        $home = $this->pmssMakeTempDir('pmss-oc-tab-').'/fixtur01';
        mkdir($home.'/.lighttpd/custom.d', 0700, true);
        $dir = $home.'/.lighttpd/custom.d';
        $this->assertFalse(isset(\pmssLocalFrameProxyAppFramesRead($dir, $home)['openclaw']));
        file_put_contents($dir.'/openclaw.conf', '$HTTP["url"] =~ "^/user-fixtur01/openclaw($|/)" { }');
        $frames = \pmssLocalFrameProxyAppFramesRead($dir, $home);
        $this->assertTrue(isset($frames['openclaw']));
        $this->assertSame('configure.php#apps', $frames['openclaw']['url']);
        $this->assertFalse(\pmssFrameOpensInNewWindow($frames['openclaw']));
    }

    public function testCustomFrameReaderRunsBeforeLocalFrameMerge(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'etc/skel/www/index.php',
            [
                'function pmssLocalFrameCustomFramesRead($path = \'../.customFrames\')',
                '$frameData = pmssLocalFrameCustomFramesRead();',
                'foreach (array(pmssLocalFrameInstalledAppFramesRead(), pmssLocalFrameProxyAppFramesRead(), pmssLocalFrameConsoleFrameRead(), pmssLocalFrameAppsFrameRead(), pmssLocalFrameConfigureFrameRead()) as $pmssCandidateFrames) {',
                '$frames = array_merge($frames, $frameData);',
            ],
            'Missing index.php frame handling fragment: ',
            'index.php custom frame merge order changed at: '
        );
    }

    public function testWikiFrameUsesInPageTabWithoutNewWindowTarget(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'etc/skel/www/index.php',
            [
                'function pmssFrameOpensInNewWindow(array $frame)',
                "'wiki' => pmssLocalFrameDefinition('https://wiki.pulsedmedia.com', 'wiki', 'Pulsed Media Wiki'),",
            ],
            'Missing index.php in-page wiki fragment: ',
            'index.php wiki target order changed at: '
        );

        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/skel/www/index.php',
            [
                'Wiki is an in-page iframe tab, not a new window.',
                "\$styleList[] = '#' . \$thisId;",
            ],
            'Missing in-page wiki handling fragment: '
        );

        $this->pmssAssertRepoFileNotContainsString(
            'etc/skel/www/index.php',
            "'target'   => '_blank',",
            'Wiki should not define a new-window target.'
        );
    }

    public function testLocalFallbackFrameUsesQuotaAwareWelcomeBuilder(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'etc/skel/www/index.php',
            [
                'function pmssLocalFrameWelcomeUrlBuild($quotaPath = \'../.quota\')',
                "'welcome' => pmssLocalFrameDefinition(pmssLocalFrameWelcomeUrlBuild(), 'welcome', 'Welcome to your seedbox. Basic information'),",
            ],
            'Missing local welcome quota fragment: ',
            'Local welcome URL builder must be defined before frame fallback use: '
        );
    }

    public function testIndexFetchesRemoteFramesWithLocalFallback(): void
    {
        // Remote guiFrames is the PRIMARY on-load GUI auto-update path; local
        // frames are the FAILOVER. Reverted #601 per operator directive
        // 2026-06-03 (removing the primary instead of keeping local as failover
        // was the defect). The remote block must be present AND every failure
        // path must fall back to the local frame set.
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'etc/skel/www/index.php',
            [
                'gui'.'Frames.php?v=2',
                "getenv('PMSS_DISABLE_REMOTE_FRAMES')",
                '!pmssGuiFramesLocalOnlyEnabled()',
                'eval'.'($frames'.'Code)',
                '$useLocalFrames = true;',
            ],
            ['Build the local tab set from bundled customer-tree definitions.']
        );
    }

    public function testLocalOnlyFramePreferenceRoundTripsThroughRegularMarker(): void
    {
        $this->loadIndexFrameHelpers();
        $marker = $this->pmssMakeTempPath('pmss-gui-frames-local-only-');

        $this->assertFalse(\pmssGuiFramesLocalOnlyEnabled($marker));
        $this->assertTrue(\pmssGuiFramesLocalOnlyPreferenceApply('local', $marker));
        $this->assertTrue(\pmssGuiFramesLocalOnlyEnabled($marker));
        $this->assertTrue(\pmssGuiFramesLocalOnlyPreferenceApply('local', $marker), 'local mode should be idempotent');
        $this->assertTrue(\pmssGuiFramesLocalOnlyPreferenceApply('remote', $marker));
        $this->assertFalse(\pmssGuiFramesLocalOnlyEnabled($marker));
        $this->assertTrue(\pmssGuiFramesLocalOnlyPreferenceApply('remote', $marker), 'remote mode should be idempotent');
    }

    public function testLocalOnlyFramePreferenceRejectsInvalidAndUnsafeTargets(): void
    {
        $this->loadIndexFrameHelpers();
        $marker = $this->pmssMakeTempPath('pmss-gui-frames-invalid-');
        $this->assertFalse(\pmssGuiFramesLocalOnlyPreferenceApply('invalid', $marker));
        $this->assertFalse(file_exists($marker));

        $directory = $this->pmssMakeTempDir('pmss-gui-frames-directory-');
        $this->assertFalse(\pmssGuiFramesLocalOnlyPreferenceApply('local', $directory));
        $this->assertFalse(\pmssGuiFramesLocalOnlyPreferenceApply('remote', $directory));

        $target = $this->pmssMakeTempFile('pmss-gui-frames-target-');
        $link = $this->pmssMakeTempPath('pmss-gui-frames-link-');
        $this->pmssCreateSymlinkOrSkip($target, $link);
        $this->assertFalse(\pmssGuiFramesLocalOnlyPreferenceApply('local', $link));
        $this->assertFalse(\pmssGuiFramesLocalOnlyPreferenceApply('remote', $link));
        $this->assertFalse(\pmssGuiFramesLocalOnlyEnabled($link));
    }

    public function testFramePreferenceWriteRequiresSameOriginAjaxPost(): void
    {
        $this->loadIndexFrameHelpers();
        $this->assertTrue(\pmssGuiFramesPreferenceRequestAllowed(array(
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        )));
        $this->assertTrue(\pmssGuiFramesPreferenceRequestAllowed(array(
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_REQUESTED_WITH' => 'xmlhttprequest',
        )));
        $this->assertFalse(\pmssGuiFramesPreferenceRequestAllowed(array('REQUEST_METHOD' => 'GET')));
        $this->assertFalse(\pmssGuiFramesPreferenceRequestAllowed(array('REQUEST_METHOD' => 'POST')));
        $this->assertFalse(\pmssGuiFramesPreferenceRequestAllowed(array(
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_REQUESTED_WITH' => 'fetch',
        )));
    }

    public function testPerUserLocalOnlyMarkerSelectsBundledFramesWithoutEnvironmentFlag(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-local-only-home-', 'fixtur01');
        $this->pmssWriteFile($home.'/.guiFramesLocalOnly', '');

        $html = '';
        $this->pmssWithEnv(array('PMSS_DISABLE_REMOTE_FRAMES' => null), function () use (&$html, $home): void {
            $html = $this->pmssRenderUserPanelIndexFromHome($home);
        });

        $this->assertStringContainsString('<a href="#welcome"', $html);
        $this->assertStringContainsString('<iframe id="welcomeFrame"', $html);
    }

    public function testWelcomePageOffersScopedFrameSourceControl(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/welcome.php', array(
            'Panel frame source',
            "pmssCustomerContextualHelpLinkBuild('panelFrameSource')",
            'Remote-updated frame definitions are the default.',
            'Other panel content may still use remote resources.',
            "headers: {'X-Requested-With': 'XMLHttpRequest'}",
            "data: {guiFramesMode: mode}",
        ));
    }

    public function testUsageAlertPreferenceRoundTripsThroughRegularMarker(): void
    {
        $this->loadIndexFrameHelpers();
        $marker = $this->pmssMakeTempPath('pmss-usage-alerts-enabled-');

        $this->assertFalse(\pmssUsageAlertsEnabled($marker));
        $this->assertTrue(\pmssUsageAlertsPreferenceApply('enabled', $marker));
        $this->assertTrue(\pmssUsageAlertsEnabled($marker));
        $this->assertSame(0600, fileperms($marker) & 0777);
        $this->assertTrue(\pmssUsageAlertsPreferenceApply('enabled', $marker));
        chmod($marker, 0644);
        clearstatcache(true, $marker);
        $this->assertFalse(\pmssUsageAlertsEnabled($marker));
        $this->assertTrue(\pmssUsageAlertsPreferenceApply('enabled', $marker));
        $this->assertSame(0600, fileperms($marker) & 0777);
        $this->assertTrue(\pmssUsageAlertsPreferenceApply('disabled', $marker));
        $this->assertFalse(\pmssUsageAlertsEnabled($marker));
    }

    public function testUsageAlertPreferenceRejectsInvalidAndUnsafeTargets(): void
    {
        $this->loadIndexFrameHelpers();
        $marker = $this->pmssMakeTempPath('pmss-usage-alerts-invalid-');
        $this->assertFalse(\pmssUsageAlertsPreferenceApply('invalid', $marker));

        $directory = $this->pmssMakeTempDir('pmss-usage-alerts-directory-');
        $this->assertFalse(\pmssUsageAlertsPreferenceApply('enabled', $directory));
        $target = $this->pmssMakeTempFile('pmss-usage-alerts-target-');
        $link = $this->pmssMakeTempPath('pmss-usage-alerts-link-');
        $this->pmssCreateSymlinkOrSkip($target, $link);
        $this->assertFalse(\pmssUsageAlertsPreferenceApply('enabled', $link));
        $this->assertFalse(\pmssUsageAlertsPreferenceApply('disabled', $link));
    }

    public function testWelcomePageOffersUsageAlertControl(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/welcome.php', array(
            'Usage alert email',
            'traffic reaches 80%',
            'disk usage reaches 90%',
            "data: {usageAlertsMode: mode}",
        ));
    }

    public function testContextualHelpLinkRequiresPublishedWikiTarget(): void
    {
        $html = \pmssCustomerContextualHelpLinkBuild('panelFrameSource');

        $this->assertStringContainsString('class="pmss-contextual-help"', $html);
        $this->assertStringContainsString('href="https://wiki.pulsedmedia.com/index.php/Panel_Frame_Source"', $html);
        $this->assertStringContainsString('title="Read about panel frame sources in the Pulsed Media Wiki"', $html);
        $this->assertStringContainsString('aria-label="Read about panel frame sources in the Pulsed Media Wiki"', $html);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('>(?)</a>', $html);

        $this->assertSame('', \pmssCustomerContextualHelpLinkBuild(null));
        $this->assertSame('', \pmssCustomerContextualHelpLinkBuild(''));
        $this->assertSame('', \pmssCustomerContextualHelpLinkBuild('panel-frame-source'));
        $this->assertSame('', \pmssCustomerContextualHelpLinkBuild('PanelFrameSource'));
        $this->assertSame('', \pmssCustomerContextualHelpLinkBuild('panelFrameSource '));
        $this->assertSame('', \pmssCustomerContextualHelpLinkBuild(array('panelFrameSource')));
    }

    public function testLocalFallbackWelcomeUrlCarriesSerializedQuotaSnapshot(): void
    {
        $this->loadIndexFrameHelpers();
        $gib = 1024 * 1024 * 1024;
        $quotaPath = $this->writeQuotaSnapshotContent(
            "Disk quotas for user panel (uid 1000):\n"
            ."     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n"
            ."       /dev/md4    594G   1380G   1725G            5642    690k    863k\n"
        );

        $quotaInfo = $this->quotaInfoFromWelcomeUrl(\pmssLocalFrameWelcomeUrlBuild($quotaPath));

        $this->assertSame(
            array(
                'overQuota' => false,
                'totalSpace' => 1380 * $gib,
                'freeSpace' => 786 * $gib,
                'hardLimit' => 1725 * $gib,
                'usedBytes' => 594 * $gib,
            ),
            $quotaInfo
        );
    }

    public function testLocalFallbackQuotaParserHandlesWrappedDeviceLine(): void
    {
        $this->loadIndexFrameHelpers();
        $tib = pow(1024, 4);
        $quotaPath = $this->writeQuotaSnapshotContent(
            "Disk quotas for user panel (uid 1000):\n"
            ."     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n"
            ."       /dev/mapper/vg-home\n"
            ."                  1.5T      2T      3T               0       0       0\n"
        );

        $quotaInfo = \pmssLocalFrameQuotaInfoRead($quotaPath);

        $this->assertSame((int) round(1.5 * $tib), $quotaInfo['usedBytes']);
        $this->assertSame((int) round(2 * $tib), $quotaInfo['totalSpace']);
        $this->assertSame((int) round(3 * $tib), $quotaInfo['hardLimit']);
    }

    public function testLocalFallbackQuotaParserMarksOverHardLimit(): void
    {
        $this->loadIndexFrameHelpers();
        $quotaPath = $this->writeQuotaSnapshotContent(
            "Disk quotas for user panel (uid 1000):\n"
            ."     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n"
            ."       /dev/md4      4T      2T      3T               0       0       0\n"
        );

        $quotaInfo = \pmssLocalFrameQuotaInfoRead($quotaPath);

        $this->assertSame(true, $quotaInfo['overQuota']);
        $this->assertTrue($quotaInfo['freeSpace'] < 0, 'Quota payload should preserve over-soft-limit free-space debt.');
    }

    public function testLocalFallbackWelcomeUrlStaysPlainWhenQuotaMissingOrInvalid(): void
    {
        $this->loadIndexFrameHelpers();
        $missingPath = $this->pmssMakeTempPath('pmss-index-missing-quota-', '.txt');
        $invalidPath = $this->writeQuotaSnapshotContent("Disk quotas for user panel (uid 1000):\ninvalid\n");

        $this->assertSame('welcome.php', \pmssLocalFrameWelcomeUrlBuild($missingPath));
        $this->assertSame('welcome.php', \pmssLocalFrameWelcomeUrlBuild($invalidPath));
    }

    public function testRemoteDisabledRenderCarriesQuotaInWelcomeIframe(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-home-', 'fixtur01');
        $this->pmssWriteFile(
            $home.'/.quota',
            "Disk quotas for user panel (uid 1000):\n"
            ."     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n"
            ."       /dev/md4    594G   1380G   1725G            5642    690k    863k\n"
        );

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));
        $this->assertSame(1, preg_match('/<iframe id="welcomeFrame"[^>]*src="([^"]+)"/', $html, $m), 'missing welcome iframe');
        $src = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

        $quotaInfo = $this->quotaInfoFromWelcomeUrl($src);
        $this->assertSame(594 * 1024 * 1024 * 1024, $quotaInfo['usedBytes']);
    }

    public function testRemoteDisabledRenderAddsDelugeFrameWhenEnabled(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-deluge-home-', 'fixtur01');
        touch($home.'/.delugeEnable');
        touch($home.'/www/deluge.php');

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        $this->assertStringContainsAllStrings(['<a href="#deluge"', 'title="Deluge - Torrent web UI"',
            "loadFrame('deluge', 'deluge/')", '<div id="deluge" class="tabs-container"></div>'], $html);
    }

    public function testRemoteDisabledRenderAddsTorrentFramesFromProxyFragments(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-proxy-root-', 'fixtur01');
        $this->pmssEnsureDir($home.'/.lighttpd/custom.d');
        touch($home.'/.qbittorrentEnable');
        touch($home.'/.delugeEnable');
        $this->pmssWriteFile(
            $home.'/.lighttpd/custom.d/pmss-qbittorrent.conf',
            '$HTTP["url"] =~ "^/user-fixtur01/qbittorrent/" {'."\n}\n"
        );
        $this->pmssWriteFile(
            $home.'/.lighttpd/custom.d/pmss-deluge.conf',
            '$HTTP["url"] =~ "^/user-fixtur01/deluge/" {'."\n}\n"
        );

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        foreach (['qbittorrent', 'deluge'] as $frame) {
            $this->assertStringContainsString('<a href="#'.$frame.'"', $html);
            $this->assertStringContainsString("loadFrame('".$frame."', '".$frame."/')", $html);
        }
        $this->assertStringContainsAllStrings(['title="qBittorrent - Torrent web UI"',
            '<div id="qbittorrent" class="tabs-container"></div>'], $html);
    }

    public function testRemoteDisabledRenderSkipsToggleAppProxyFragmentsWithoutEnableFlags(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-disabled-proxy-root-', 'fixtur01');
        $this->pmssEnsureDir($home.'/.lighttpd/custom.d');
        $this->pmssWriteFile(
            $home.'/.lighttpd/custom.d/pmss-qbittorrent.conf',
            '$HTTP["url"] =~ "^/user-fixtur01/qbittorrent/" {'."\n}\n"
        );
        $this->pmssWriteFile(
            $home.'/.lighttpd/custom.d/pmss-rclone.conf',
            '$HTTP["url"] =~ "^/user-fixtur01/rclone/" {'."\n}\n"
        );

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        $this->assertStringContainsAndOmitsStrings([], ['<a href="#qbittorrent"', "loadFrame('qbittorrent', 'qbittorrent/')",
            '<a href="#rclone"', "loadFrame('rclone', 'rclone/')"], $html);
    }

    public function testRemoteDisabledRenderAddsRcloneFrameFromProxyFragment(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-rclone-root-', 'fixtur01');
        $this->pmssEnsureDir($home.'/.lighttpd/custom.d');
        touch($home.'/.rcloneEnable');
        $this->pmssWriteFile(
            $home.'/.lighttpd/custom.d/pmss-rclone.conf',
            '$HTTP["url"] =~ "^/user-fixtur01/rclone/" {'."\n}\n"
        );

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        $this->assertStringContainsAllStrings(['<a href="#rclone"', 'title="Rclone Web UI"',
            "loadFrame('rclone', 'rclone/')", '<div id="rclone" class="tabs-container"></div>'], $html);
    }

    public function testRemoteEnabledRenderAddsTorrentFramesFromLocalConfigDirs(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-config-root-', 'fixtur01');
        $this->pmssEnsureDir($home.'/.config/qBittorrent');
        $this->pmssEnsureDir($home.'/.config/deluge');
        $this->pmssWriteFile($home.'/.rclonePort', "45678\n");
        touch($home.'/.qbittorrentEnable');
        touch($home.'/.delugeEnable');
        touch($home.'/.rcloneEnable');

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        $this->assertStringContainsAllStrings(['<a href="#qbittorrent"', "loadFrame('qbittorrent', 'qbittorrent/')",
            '<a href="#deluge"', "loadFrame('deluge', 'deluge/')", '<a href="#rclone"', "loadFrame('rclone', 'rclone/')"], $html);
    }

    public function testRemoteDisabledRenderSkipsAutobrrConfigDirWithoutProxyFragment(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-autobrr-config-root-', 'fixtur01');
        $this->pmssEnsureDir($home.'/.config/autobrr');

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        $this->assertStringContainsAndOmitsStrings([], ['<a href="#autobrr"', "loadFrame('autobrr', '/public-fixtur01/autobrr/')"], $html);
    }

    public function testRemoteDisabledRenderSkipsTorrentFramesFromLocalConfigDirsWithoutEnableFlags(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-disabled-config-root-', 'fixtur01');
        $this->pmssEnsureDir($home.'/.config/qBittorrent');
        $this->pmssEnsureDir($home.'/.config/deluge');
        $this->pmssWriteFile($home.'/.rclonePort', "45678\n");

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        $this->assertStringContainsAndOmitsStrings([], ['<a href="#qbittorrent"', "loadFrame('qbittorrent', 'qbittorrent/')",
            '<a href="#deluge"', "loadFrame('deluge', 'deluge/')", '<a href="#rclone"', "loadFrame('rclone', 'rclone/')"], $html);
    }

    public function testRemoteDisabledRenderAddsMediaStackFramesFromProxyFragment(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-media-root-', 'fixtur01');
        $this->pmssEnsureDir($home.'/.lighttpd/custom.d');
        $this->pmssWriteFile(
            $home.'/.lighttpd/custom.d/media-stack.conf',
            implode("\n", array(
                '$HTTP["url"] =~ "^/sabnzbd($|/)" {',
                '}',
                '$HTTP["url"] =~ "^/radarr($|/)" {',
                '}',
                '$HTTP["url"] =~ "^/prowlarr($|/)" {',
                '}',
                '$HTTP["url"] =~ "^/sonarr($|/)" {',
                '}',
                '$HTTP["url"] =~ "^/lidarr($|/)" {',
                '}',
                '$HTTP["url"] =~ "^/readarr($|/)" {',
                '}',
                '$HTTP["url"] =~ "^/jellyfin($|/)" {',
                '}',
                '$HTTP["url"] =~ "^/autobrr($|/)" {',
                '}',
                '$HTTP["url"] =~ "^/notebook($|/)" {',
                '}',
            ))."\n"
        );

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        foreach (['sabnzbd', 'radarr', 'prowlarr', 'sonarr', 'lidarr', 'readarr', 'autobrr'] as $app) {
            $this->assertStringContainsString("loadFrame('".$app."', '/public-fixtur01/".$app."/')", $html);
        }
        $this->assertStringContainsString("loadFrame('jellyfin', '/public-fixtur01/jellyfin/web/')", $html);
        $this->assertStringNotContainsString('<a href="#notebook"', $html);
    }

    public function testCustomFrameParserIgnoresBlankLinesBeforeFieldAccess(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-custom-home-', 'fixtur01');
        $this->pmssWriteFile($home.'/.customFrames', "custom|Custom tooltip|Custom|custom/\n\n");

        $output = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'), true);

        $this->assertStringContainsString('<a href="#custom"', $output);
        $this->assertStringNotContainsString('Undefined array key', $output);
    }

    public function testCustomFrameParserLocksAcceptedRows(): void
    {
        $this->loadIndexFrameHelpers();
        $path = $this->pmssMakeTempPath('pmss-index-custom-frames-', '.txt');
        $this->pmssWriteFile(
            $path,
            "# comment\n"
            ."\n"
            ."custom|Custom tooltip|Custom|custom/\n"
            ."missing-url|Missing URL|Missing|\n"
            ."short|Short row|Short\n"
            ."second|Second tooltip|Second|https://example.test/app/\n"
        );

        $this->assertSame(
            array(
                'custom' => array(
                    'url' => 'custom/',
                    'linkText' => 'Custom',
                    'title' => 'Custom tooltip',
                ),
                'second' => array(
                    'url' => 'https://example.test/app/',
                    'linkText' => 'Second',
                    'title' => 'Second tooltip',
                ),
            ),
            \pmssLocalFrameCustomFramesRead($path)
        );
    }

    /** Build frames keyed as given, each carrying distinct values so reordering can be checked whole. */
    private function priorityFrames(array $keys): array
    {
        $this->loadIndexFrameHelpers();
        $frames = array();
        foreach ($keys as $key) {
            $frames[$key] = \pmssLocalFrameDefinition(strtolower($key).'/', $key, $key.' tooltip');
        }
        return $frames;
    }

    public function testPriorityOrderKeepsWelcomeFirstAgainstHighestPriority(): void
    {
        $frames = $this->priorityFrames(array('rutorrent', 'welcome', 'wiki'));
        $ordered = \pmssLocalFramePriorityOrder($frames, array('wiki' => 99, 'welcome' => -99), false);

        $this->assertSame(array('welcome', 'wiki', 'rutorrent'), array_keys($ordered));
        $this->assertSame($frames['wiki'], $ordered['wiki'], 'Reordering must carry each frame definition unchanged');
        $this->assertSame($frames['welcome'], $ordered['welcome']);
    }

    public function testPriorityOrderPutsPikkumiesRightAfterWelcomeByDefault(): void
    {
        $frames = $this->priorityFrames(array('welcome', 'rutorrent', 'files', 'pikkumies'));

        $this->assertSame(
            array('welcome', 'pikkumies', 'rutorrent', 'files'),
            array_keys(\pmssLocalFramePriorityOrder($frames, array(), false))
        );
    }

    public function testPriorityOrderLeadsWithOpenClawOnlyOnOpenClawFirstAccounts(): void
    {
        $frames = $this->priorityFrames(array('welcome', 'pikkumies', 'rutorrent', 'openclaw', 'wiki'));

        $this->assertSame(
            array('welcome', 'openclaw', 'pikkumies', 'rutorrent', 'wiki'),
            array_keys(\pmssLocalFramePriorityOrder($frames, array(), true))
        );
        $this->assertSame(
            array('welcome', 'pikkumies', 'rutorrent', 'openclaw', 'wiki'),
            array_keys(\pmssLocalFramePriorityOrder($frames, array(), false))
        );
    }

    public function testPriorityOrderLetsAccountLinesOverrideDefaults(): void
    {
        $frames = $this->priorityFrames(array('welcome', 'pikkumies', 'rutorrent', 'openclaw', 'wiki'));

        $this->assertSame(
            array('welcome', 'pikkumies', 'rutorrent', 'wiki', 'openclaw'),
            array_keys(\pmssLocalFramePriorityOrder($frames, array('openclaw' => -5), true)),
            'An explicit negative priority moves OpenClaw after the priority-0 tabs'
        );
        $this->assertSame(
            array('welcome', 'openclaw', 'rutorrent', 'pikkumies', 'wiki'),
            array_keys(\pmssLocalFramePriorityOrder(
                $this->priorityFrames(array('welcome', 'rutorrent', 'pikkumies', 'openclaw', 'wiki')),
                array('pikkumies' => 0),
                true
            )),
            'An explicit 0 returns Pikkumies to its place among the other tabs'
        );
    }

    public function testPriorityOrderIsStableAmongEqualPriorities(): void
    {
        $keys = array('welcome', 'rutorrent', 'files', 'Custom0', 'wiki', 'custom1');
        $frames = $this->priorityFrames($keys);

        $this->assertSame($keys, array_keys(\pmssLocalFramePriorityOrder($frames, array(), false)));
        $this->assertSame(
            array('welcome', 'Custom0', 'custom1', 'rutorrent', 'files', 'wiki'),
            array_keys(\pmssLocalFramePriorityOrder($frames, array('custom1' => 10, 'custom0' => 10), false)),
            'Equal priorities keep their original order; frame keys match priorities case-insensitively'
        );
    }

    public function testPrioritiesReaderAcceptsOnlyBoundedFifthField(): void
    {
        $this->loadIndexFrameHelpers();
        $path = $this->pmssMakeTempPath('pmss-index-priorities-', '.txt');
        $this->pmssWriteFile(
            $path,
            "# comment|t|l|u|50\n"
            ."custom0|Tip|Label|https://example.test/|10\r\n"
            ."openclaw||||-5\n"
            ."Wiki||||99\n"
            ."lowest||||-99\n"
            ."spaced|t|l|u| 3 \n"
            ."toohigh||||100\n"
            ."toolow||||-100\n"
            ."word||||abc\n"
            ."plain|t|l|u\n"
            ."||||7\n"
            ."\n"
        );

        $this->assertSame(
            array('custom0' => 10, 'openclaw' => -5, 'wiki' => 99, 'lowest' => -99, 'spaced' => 3),
            \pmssLocalFramePrioritiesRead($path)
        );
        $this->assertSame(array(), \pmssLocalFramePrioritiesRead($this->pmssMakeTempPath('pmss-index-priorities-missing-')));

        $link = $this->pmssMakeTempPath('pmss-index-priorities-link-');
        $this->pmssCreateSymlinkOrSkip($path, $link);
        $this->assertSame(array(), \pmssLocalFramePrioritiesRead($link), 'A symlinked ~/.customFrames is not trusted');

        $large = $this->pmssWriteFile($this->pmssMakeTempPath('pmss-index-priorities-large-'), "big||||9\n".str_repeat('#', 4096));
        $this->assertSame(array(), \pmssLocalFramePrioritiesRead($large));
    }

    public function testPositionOnlyLinesDoNotBecomeCustomTabs(): void
    {
        $this->loadIndexFrameHelpers();
        $path = $this->pmssWriteFile(
            $this->pmssMakeTempPath('pmss-index-position-lines-', '.txt'),
            "openclaw||||50\ncustom0|Tip|Label|https://example.test/|10\n"
        );

        $this->assertSame(
            array('custom0' => array('url' => 'https://example.test/', 'linkText' => 'Label', 'title' => 'Tip')),
            \pmssLocalFrameCustomFramesRead($path)
        );
    }

    public function testDisabledRtorrentDropsRutorrentTabByKeyOrUrl(): void
    {
        $this->loadIndexFrameHelpers();
        $frames = array(
            'welcome' => \pmssLocalFrameDefinition('welcome.php', 'welcome', 'Welcome'),
            'RuTorrent' => \pmssLocalFrameDefinition('somewhere/', 'ruTorrent', 'ruTorrent'),
            'torrents' => \pmssLocalFrameDefinition('rutorrent/', 'Torrents', 'Torrents'),
            'absolute' => \pmssLocalFrameDefinition('/user-fixtur01/ruTorrent', 'Absolute', 'Absolute'),
            'lookalike' => \pmssLocalFrameDefinition('rutorrent-extra/', 'Lookalike', 'Lookalike'),
            'nourl' => array('linkText' => 'No URL', 'title' => 'No URL'),
        );

        $this->assertSame(
            array('welcome', 'lookalike', 'nourl'),
            array_keys(\pmssLocalFrameDisabledRtorrentDrop($frames, true))
        );
        $this->assertSame($frames, \pmssLocalFrameDisabledRtorrentDrop($frames, false));
    }

    public function testIndexAppliesRtorrentDropBeforePriorityOrderAndConfigure(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'etc/skel/www/index.php',
            array(
                '$pmssRtorrentDisabled = file_exists(\'../.rtorrentDisable\');',
                '$frames = pmssLocalFrameDisabledRtorrentDrop($frames, $pmssRtorrentDisabled);',
                '$frames = pmssLocalFramePriorityOrder($frames, pmssLocalFramePrioritiesRead(), $pmssRtorrentDisabled);',
                '$pmssConfigureFrame = pmssLocalFrameConfigureFrameRead();',
            ),
            'Missing index.php tab priority wiring: ',
            'index.php tab priority wiring order changed at: '
        );
    }

    public function testUserSkeletonSyncIncludesIndexTemplate(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/users/filesystem.php', "'www/index.php',");
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/lib/update/users/filesystem.php',
            [
                'pmssUserRefreshPanelIndexForFrameDataCompat($ctx);',
                "'www/index.php',",
            ],
            'Missing panel index compatibility refresh wiring: ',
            'Panel index compatibility refresh should run before normal skeleton sync: '
        );
    }

    public function testPanelIndexMigrationGuardTracksCurrentSkeletonHelperSignature(): void
    {
        $guard = 'function pmssFrameOpensInNewWindow(array $frame)';

        $this->pmssAssertRepoFileContainsString(
            'etc/skel/www/index.php',
            $guard,
            'Skeleton helper signature changed: '
        );
        $this->pmssAssertRepoFileContainsString(
            'scripts/lib/update/users/filesystem.php',
            $guard,
            'Panel index migration guard should track the current skeleton helper signature: '
        );
    }

    public function testIndexSkeletonAvoidsPhp7FunctionDeclarations(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/index.php');

        $this->assertEquals(
            0,
            preg_match('/function\s+\w+\([^)]*\)\s*:\s*\??[A-Za-z_][A-Za-z0-9_]*/', $source),
            'Customer panel index.php must parse on PHP 5.6, so function return types are not allowed.'
        );
        $this->assertEquals(
            0,
            preg_match('/function\s+\w+\([^)]*\b(?:string|int|bool|float)\s+\$/', $source),
            'Customer panel index.php must parse on PHP 5.6, so scalar parameter types are not allowed.'
        );
    }

    private function loadIndexFrameHelpers(): void
    {
        if (function_exists('pmssLocalFrameWelcomeUrlBuild')) {
            return;
        }

        $source = $this->pmssReadRepoFile('etc/skel/www/index.php');
        $start = strpos($source, '/** Detect frames that must open outside the iframe tab container. */');
        // Extract ONLY the helper-function definitions. End at the remote-frames
        // procedural block (restored with #601 revert) so the extracted fixture
        // does NOT execute the top-level guiFrames fetch/eval when required.
        $end = strpos($source, '// Remote frames can be disabled explicitly for debugging or fully offline');
        $this->assertTrue($start !== false && $end !== false && $end > $start, 'index.php helper extraction markers changed');

        $fixture = $this->pmssMakeTempPath('pmss-index-frame-helpers-', '.php');
        file_put_contents($fixture, "<?php\n".substr($source, $start, $end - $start));
        require_once $fixture;
    }

    private function writeQuotaSnapshotContent(string $content): string
    {
        $path = $this->pmssMakeTempPath('pmss-index-quota-', '.txt');
        file_put_contents($path, $content);
        return $path;
    }

    private function quotaInfoFromWelcomeUrl(string $url): array
    {
        $prefix = 'welcome.php?quota=';
        $this->assertTrue(strpos($url, $prefix) === 0, 'welcome URL should carry a quota query parameter');

        $quotaInfo = @unserialize(urldecode(substr($url, strlen($prefix))));
        $this->assertTrue(is_array($quotaInfo), 'welcome URL quota payload should decode to an array');

        return $quotaInfo;
    }

}
