<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class IndexSkeletonFrameDataTest extends TestCase
{
    public function testCustomFrameReaderRunsBeforeLocalFrameMerge(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'etc/skel/www/index.php',
            [
                'function pmssLocalFrameCustomFramesRead($path = \'../.customFrames\')',
                '$frameData = pmssLocalFrameCustomFramesRead();',
                'foreach (array(pmssLocalFrameInstalledAppFramesRead(), pmssLocalFrameProxyAppFramesRead()) as $pmssCandidateFrames) {',
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
                'eval'.'($frames'.'Code)',
                '$useLocalFrames = true;',
            ],
            ['Build the local tab set from bundled customer-tree definitions.']
        );
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

        $quotaInfo = $this->quotaInfoFromWelcomeUrl($this->localFrameWelcomeUrlBuild($quotaPath));

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

        $quotaInfo = $this->localFrameQuotaInfoRead($quotaPath);

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

        $quotaInfo = $this->localFrameQuotaInfoRead($quotaPath);

        $this->assertSame(true, $quotaInfo['overQuota']);
        $this->assertTrue($quotaInfo['freeSpace'] < 0, 'Quota payload should preserve over-soft-limit free-space debt.');
    }

    public function testLocalFallbackWelcomeUrlStaysPlainWhenQuotaMissingOrInvalid(): void
    {
        $this->loadIndexFrameHelpers();
        $missingPath = $this->pmssMakeTempPath('pmss-index-missing-quota-', '.txt');
        $invalidPath = $this->writeQuotaSnapshotContent("Disk quotas for user panel (uid 1000):\ninvalid\n");

        $this->assertSame('welcome.php', $this->localFrameWelcomeUrlBuild($missingPath));
        $this->assertSame('welcome.php', $this->localFrameWelcomeUrlBuild($invalidPath));
    }

    public function testRemoteDisabledRenderCarriesQuotaInWelcomeIframe(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-home-');
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
        $home = $this->pmssMakeUserWebHome('pmss-index-deluge-home-');
        touch($home.'/.delugeEnable');
        touch($home.'/www/deluge.php');

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        foreach (['<a href="#deluge"', 'title="Deluge - Torrent web UI"', "loadFrame('deluge', 'deluge/')", '<div id="deluge" class="tabs-container"></div>'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function testRemoteDisabledRenderAddsTorrentFramesFromProxyFragments(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-proxy-root-');
        $this->pmssEnsureDir($home.'/.lighttpd/custom.d');
        touch($home.'/.qbittorrentEnable');
        touch($home.'/.delugeEnable');
        $this->pmssWriteFile(
            $home.'/.lighttpd/custom.d/pmss-qbittorrent.conf',
            '$HTTP["url"] =~ "^/user-alice/qbittorrent/" {'."\n}\n"
        );
        $this->pmssWriteFile(
            $home.'/.lighttpd/custom.d/pmss-deluge.conf',
            '$HTTP["url"] =~ "^/user-alice/deluge/" {'."\n}\n"
        );

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        foreach (['qbittorrent', 'deluge'] as $frame) {
            $this->assertStringContainsString('<a href="#'.$frame.'"', $html);
            $this->assertStringContainsString("loadFrame('".$frame."', '".$frame."/')", $html);
        }
        foreach (['title="qBittorrent - Torrent web UI"', '<div id="qbittorrent" class="tabs-container"></div>'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function testRemoteDisabledRenderSkipsToggleAppProxyFragmentsWithoutEnableFlags(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-disabled-proxy-root-');
        $this->pmssEnsureDir($home.'/.lighttpd/custom.d');
        $this->pmssWriteFile(
            $home.'/.lighttpd/custom.d/pmss-qbittorrent.conf',
            '$HTTP["url"] =~ "^/user-alice/qbittorrent/" {'."\n}\n"
        );
        $this->pmssWriteFile(
            $home.'/.lighttpd/custom.d/pmss-rclone.conf',
            '$HTTP["url"] =~ "^/user-alice/rclone/" {'."\n}\n"
        );

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        foreach (['<a href="#qbittorrent"', "loadFrame('qbittorrent', 'qbittorrent/')", '<a href="#rclone"', "loadFrame('rclone', 'rclone/')"] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function testRemoteDisabledRenderAddsRcloneFrameFromProxyFragment(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-rclone-root-');
        $this->pmssEnsureDir($home.'/.lighttpd/custom.d');
        touch($home.'/.rcloneEnable');
        $this->pmssWriteFile(
            $home.'/.lighttpd/custom.d/pmss-rclone.conf',
            '$HTTP["url"] =~ "^/user-alice/rclone/" {'."\n}\n"
        );

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        foreach (['<a href="#rclone"', 'title="Rclone Web UI"', "loadFrame('rclone', 'rclone/')", '<div id="rclone" class="tabs-container"></div>'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function testRemoteEnabledRenderAddsTorrentFramesFromLocalConfigDirs(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-config-root-');
        $this->pmssEnsureDir($home.'/.config/qBittorrent');
        $this->pmssEnsureDir($home.'/.config/deluge');
        $this->pmssWriteFile($home.'/.rclonePort', "45678\n");
        touch($home.'/.qbittorrentEnable');
        touch($home.'/.delugeEnable');
        touch($home.'/.rcloneEnable');

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        foreach (['<a href="#qbittorrent"', "loadFrame('qbittorrent', 'qbittorrent/')", '<a href="#deluge"', "loadFrame('deluge', 'deluge/')", '<a href="#rclone"', "loadFrame('rclone', 'rclone/')"] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function testRemoteDisabledRenderSkipsAutobrrConfigDirWithoutProxyFragment(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-autobrr-config-root-');
        $this->pmssEnsureDir($home.'/.config/autobrr');

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        foreach (['<a href="#autobrr"', "loadFrame('autobrr', '/public-alice/autobrr/')"] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function testRemoteDisabledRenderSkipsTorrentFramesFromLocalConfigDirsWithoutEnableFlags(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-disabled-config-root-');
        $this->pmssEnsureDir($home.'/.config/qBittorrent');
        $this->pmssEnsureDir($home.'/.config/deluge');
        $this->pmssWriteFile($home.'/.rclonePort', "45678\n");

        $html = $this->pmssRenderUserPanelIndexFromHome($home, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'));

        foreach (['<a href="#qbittorrent"', "loadFrame('qbittorrent', 'qbittorrent/')", '<a href="#deluge"', "loadFrame('deluge', 'deluge/')", '<a href="#rclone"', "loadFrame('rclone', 'rclone/')"] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function testRemoteDisabledRenderAddsMediaStackFramesFromProxyFragment(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-media-root-');
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
            $this->assertStringContainsString("loadFrame('".$app."', '/public-alice/".$app."/')", $html);
        }
        $this->assertStringContainsString("loadFrame('jellyfin', '/public-alice/jellyfin/web/index.html')", $html);
        $this->assertStringNotContainsString('<a href="#notebook"', $html);
    }

    public function testCustomFrameParserIgnoresBlankLinesBeforeFieldAccess(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-index-custom-home-');
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
            $this->localFrameCustomFramesRead($path)
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

    private function localFrameWelcomeUrlBuild(string $quotaPath): string
    {
        $this->loadIndexFrameHelpers();
        /** @var callable(string): string $builder */
        $builder = 'pmssLocalFrameWelcomeUrlBuild';
        return $builder($quotaPath);
    }

    private function localFrameQuotaInfoRead(string $quotaPath): array
    {
        $this->loadIndexFrameHelpers();
        /** @var callable(string): array<string,mixed> $reader */
        $reader = 'pmssLocalFrameQuotaInfoRead';
        return $reader($quotaPath);
    }

    private function localFrameCustomFramesRead(string $path): array
    {
        $this->loadIndexFrameHelpers();
        /** @var callable(string): array<string,array<string,string>> $reader */
        $reader = 'pmssLocalFrameCustomFramesRead';
        return $reader($path);
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
