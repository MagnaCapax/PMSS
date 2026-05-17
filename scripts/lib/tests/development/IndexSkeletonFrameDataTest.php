<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class IndexSkeletonFrameDataTest extends TestCase
{
    public function testCustomFrameAccumulatorStartsAsArrayBeforeMerge(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/index.php');

        $this->assertOrderedStrings(
            [
                '$frameData = array();',
                "if (file_exists('../.customFrames')) {",
                '$frames = array_merge($frames, $frameData);',
            ],
            $source,
            'Missing index.php frame handling fragment: ',
            'index.php custom frame initialization order changed at: '
        );
    }

    public function testWikiFrameUsesNewWindowTargetInsteadOfIframe(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/index.php');

        $this->assertOrderedStrings(
            [
                'function pmssFrameOpensInNewWindow(array $frame)',
                "'wiki' => array(",
                "'target'   => '_blank',",
            ],
            $source,
            'Missing index.php new-window fragment: ',
            'index.php wiki target order changed at: '
        );

        $this->assertStringContainsAllStrings(
            [
                'target="_blank" rel="noopener noreferrer"',
                'if (pmssFrameOpensInNewWindow($thisFrame)) {',
                "\$styleList[] = '#' . \$thisId;",
            ],
            $source,
            'Missing external-tab handling fragment: '
        );

        $this->pmssAssertStringNotContainsString(
            "<iframe id=\"wikiFrame\"",
            $source,
            'Wiki should no longer be hard-wired into an iframe container.'
        );
    }

    public function testLocalFallbackFrameUsesQuotaAwareWelcomeBuilder(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/index.php');

        $this->assertOrderedStrings(
            [
                'function pmssLocalFrameWelcomeUrlBuild($quotaPath = \'../.quota\')',
                "'url'      => pmssLocalFrameWelcomeUrlBuild(),",
            ],
            $source,
            'Missing local welcome quota fragment: ',
            'Local welcome URL builder must be defined before frame fallback use: '
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
        $home = $this->pmssMakeTempDir('pmss-index-home-', 0755);
        @mkdir($home.'/www', 0755, true);
        file_put_contents(
            $home.'/.quota',
            "Disk quotas for user panel (uid 1000):\n"
            ."     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n"
            ."       /dev/md4    594G   1380G   1725G            5642    690k    863k\n"
        );

        $script = 'chdir('.var_export($home.'/www', true).'); '
            .'ob_start(); include '.var_export($this->pmssRepoPath('etc/skel/www/index.php'), true).'; $html = ob_get_clean(); '
            .'if (preg_match(\'/<iframe id="welcomeFrame"[^>]*src="([^"]+)"/\', $html, $m) !== 1) { fwrite(STDERR, "missing welcome iframe\n"); exit(2); } '
            .'echo html_entity_decode($m[1], ENT_QUOTES, "UTF-8");';

        $src = trim($this->pmssRunInlinePhp($script, array('PMSS_DISABLE_REMOTE_FRAMES' => '1'), '2>&1'));

        $quotaInfo = $this->quotaInfoFromWelcomeUrl($src);
        $this->assertSame(594 * 1024 * 1024 * 1024, $quotaInfo['usedBytes']);
    }

    public function testUserSkeletonSyncIncludesIndexTemplate(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');

        $this->assertStringContainsString("'www/index.php',", $source);
        $this->assertOrderedStrings(
            [
                'pmssUserRefreshPanelIndexForFrameDataCompat($ctx);',
                "'www/index.php',",
            ],
            $source,
            'Missing panel index compatibility refresh wiring: ',
            'Panel index compatibility refresh should run before normal skeleton sync: '
        );
    }

    public function testPanelIndexMigrationGuardTracksCurrentSkeletonHelperSignature(): void
    {
        $guard = 'function pmssFrameOpensInNewWindow(array $frame)';

        $this->assertStringContainsString(
            $guard,
            $this->pmssReadRepoFile('etc/skel/www/index.php'),
            'Skeleton helper signature changed: '
        );
        $this->assertStringContainsString(
            $guard,
            $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php'),
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
        $end = strpos($source, '// Remote frames can be disabled explicitly');
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
