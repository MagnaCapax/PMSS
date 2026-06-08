<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/userTransfer.php';

/**
 * Hermetic tests for user transfer helpers.
 */
class UserTransferTest extends TestCase
{
    public function setUp(): void
    {
        $this->pmssTrackEnvKeys(['PMSS_HOME_DIR', 'PMSS_DRY_RUN', 'PMSS_LOG_DIR']);
    }

    public function testHostnameValidationAcceptsExpectedNames(): void
    {
        $this->assertHostnameValidity([
            'lt5-1-56-138anger-core.pulsedmedia.com',
            'le4-0-78-95wheatley.pulsedmedia.com',
            '185.148.1.138',
            'a.b',
            'abc123.example',
            'a-b.c-d',
        ], true, 'valid');
    }

    public function testHostnameValidationRejectsBadNames(): void
    {
        $this->assertHostnameValidity([
            '',
            ' host',
            'host name',
            'host..name',
            '.host.example',
            'host.example.',
            '-host.example',
            'host-.example',
            'host_underscore.example',
            'host;rm -rf /',
        ], false, 'invalid');
    }

    public function testParseCliHandlesSupportedForms(): void
    {
        foreach ([
            [['userTransfer.php', 'deefbox', 'lt5-1-56-138anger-core'], ['localUser' => 'deefbox', 'remoteUser' => 'deefbox', 'hostname' => 'lt5-1-56-138anger-core.pulsedmedia.com', 'suffixAppended' => true]],
            [['userTransfer.php', 'deefbox', 'remote01', 'example.com'], ['localUser' => 'deefbox', 'remoteUser' => 'remote01', 'hostname' => 'example.com', 'suffixAppended' => false, 'mainPasses' => 31, 'finalPasses' => 3, 'sleepMin' => 60, 'sleepMax' => 360, 'verifyThreshold' => 90, 'dryRun' => false, 'printPassword' => false]],
            [['userTransfer.php', 'DeefBox', 'example.com'], ['localUser' => 'deefbox', 'remoteUser' => 'deefbox']],
            [['userTransfer.php', '--', 'deefbox', 'example.com'], ['localUser' => 'deefbox', 'remoteUser' => 'deefbox', 'hostname' => 'example.com']],
            [['userTransfer.php', '--no-sleep', 'deefbox', 'example.com'], ['sleepMin' => 0, 'sleepMax' => 0]],
            [['userTransfer.php', '--verify-threshold=95', 'deefbox', 'example.com'], ['verifyThreshold' => 95]],
        ] as [$argv, $expected]) {
            $cfg = \pmssUserTransferParseCli($argv);
            foreach ($expected as $key => $value) {
                $this->assertSame($value, $cfg[$key], 'unexpected '.$key.' for '.implode(' ', $argv));
            }
        }
    }

    public function testParseCliRejectsInvalidInputs(): void
    {
        foreach ([
            [['userTransfer.php', 'deef_box', 'example.com'], 'Invalid username'],
            [['userTransfer.php', 'toolongname', 'example.com'], 'Invalid username'],
            [['userTransfer.php', 'deefbox', 'bad host'], 'Invalid hostname'],
            [['userTransfer.php', 'deefbox', 'example.com', '--main-passes'], 'requires a value'],
            [['userTransfer.php', '--sleep-min=abc', 'deefbox', 'example.com'], 'expected integer'],
            [['userTransfer.php', '--verify-threshold=101', 'deefbox', 'example.com'], 'verify-threshold'],
            [['userTransfer.php', '--sleep-min=10', '--sleep-max=5', 'deefbox', 'example.com'], 'sleep-max must be'],
            [['userTransfer.php', '--main-passes=501', 'deefbox', 'example.com'], 'main-passes'],
        ] as [$argv, $messageFragment]) {
            $this->assertThrowsRuntime(static function () use ($argv): void {
                \pmssUserTransferParseCli($argv);
            }, $messageFragment);
        }
    }

    public function testParseCliHelpReturnsStableUsageText(): void
    {
        $expected = "Usage:\n"
            ."  /scripts/util/userTransfer.php LOCAL_USERNAME REMOTE_HOSTNAME\n"
            ."  /scripts/util/userTransfer.php LOCAL_USERNAME REMOTE_USERNAME REMOTE_HOSTNAME\n\n"
            ."Options:\n"
            ."  --main-passes N     Number of passes for the main rsync (default 31)\n"
            ."  --final-passes N    Number of passes for the final rsync (default 3)\n"
            ."  --sleep-min N       Minimum sleep seconds between passes (default 60)\n"
            ."  --sleep-max N       Maximum sleep seconds between passes (default 360)\n"
            ."  --verify-threshold N Warn if local size is below N% of remote (default 90)\n"
            ."  --no-sleep          Disable sleeping between passes\n"
            ."  --dry-run           Log planned steps without executing commands\n"
            ."  --print-password    Print the supplied password at the end (unsafe)\n"
            ."  --help, -h          Show this help\n\n"
            ."Notes:\n"
            ."  - If REMOTE_HOSTNAME does not contain a dot, \".pulsedmedia.com\" is appended.\n"
            ."  - Password can be provided via env: PMSS_USER_TRANSFER_PASSWORD\n";

        try {
            \pmssUserTransferParseCli(['userTransfer.php', '--help']);
            $this->fail('expected help path to throw RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertEquals(0, $e->getCode());
            $this->assertEquals($expected, $e->getMessage());
            $this->assertStringContainsString('--print-password    Print the supplied password at the end (unsafe)', $e->getMessage());
        }
    }

    public function testParseCliAcceptsSplitLongOptionValuesAndFlags(): void
    {
        $cfg = \pmssUserTransferParseCli([
            'userTransfer.php',
            '--main-passes',
            '7',
            '--final-passes',
            '2',
            '--dry-run',
            '--print-password',
            'deefbox',
            'example.com',
        ]);

        $this->assertEquals(7, $cfg['mainPasses']);
        $this->assertEquals(2, $cfg['finalPasses']);
        $this->assertTrue($cfg['dryRun']);
        $this->assertTrue($cfg['printPassword']);
        $this->assertSame('33448dbf45fb18a2927929b8432652ed3eaaef39908fd9e27f8535de57377990', hash('sha256', json_encode($cfg)));
    }

    public function testGeneratedProbeScriptsContainExpectedFragments(): void
    {
        $cfg = $this->baseConfig();

        foreach ([
            [\pmssUserTransferBuildRsyncMain($cfg), ['rsync -av', ':/home/deefbox/', '/home/deefbox/', "--exclude='.rtorrent.rc'", "--exclude='.trafficDataIngress'", "--exclude='.trafficDataIngressLocal'"], ['--exclude={' => 'expected no brace-expanded excludes']],
            [\pmssUserTransferBuildRsyncFinal($cfg), ['rsync -av', ':/home/deefbox/session', ':/home/deefbox/www/public'], ['{session' => 'expected no brace-expanded sources']],
            [\pmssUserTransferBuildAuthProbe($cfg), ['ssh -o Compression=no', '-o NumberOfPasswordPrompts=1', "-l 'deefbox'", "'example.com'", "'/bin/true'"], []],
            [\pmssUserTransferBuildRemoteSizeProbe($this->baseConfig(['remoteUser' => 'remote01', 'verifyThreshold' => 90])), ['-o NumberOfPasswordPrompts=1', "'example.com'", "/home/remote01/", "'du '\\''-sb'\\''"], []],
        ] as [$script, $required, $forbidden]) {
            $this->assertStringContainsAndOmitsStrings($required, $forbidden, $script);
        }
    }

    public function testGeneratedScriptsKeepSharedSshFlagsAligned(): void
    {
        $cfg = $this->baseConfig();
        $sharedFlags = 'ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no';

        foreach ([
            \pmssUserTransferBuildRsyncMain($cfg),
            \pmssUserTransferBuildRsyncFinal($cfg),
            \pmssUserTransferBuildAuthProbe($cfg),
            \pmssUserTransferBuildRemoteSizeProbe($cfg + ['verifyThreshold' => 90]),
        ] as $script) {
            $this->assertStringContainsString($sharedFlags, $script);
        }
    }

    public function testParseDuBytesReturnsLeadingByteCount(): void
    {
        $this->assertEquals(12345, \pmssUserTransferParseDuBytes("12345\t/home/deefbox/\n"));
    }

    public function testParseDuBytesRejectsUnreadableOutput(): void
    {
        $this->assertSame(null, \pmssUserTransferParseDuBytes("du: cannot access '/home/deefbox': Permission denied\n"));
    }

    public function testEvaluateCompletenessHandlesThresholdCases(): void
    {
        foreach ([
            [1000, 850, 90, ['remoteBytes' => 1000, 'localBytes' => 850, 'verifyThreshold' => 90, 'localPercent' => 85.0], 'below threshold'],
            [1000, 950, 90, null, 'healthy transfer'],
            [0, 0, 90, null, 'zero remote size'],
        ] as [$remoteBytes, $localBytes, $threshold, $expected, $label]) {
            $this->assertEquals($expected, \pmssUserTransferEvaluateCompleteness($remoteBytes, $localBytes, $threshold), 'unexpected result for '.$label);
        }
    }

    public function testBuildExpectWrapperUsesEnvPassword(): void
    {
        $script = \pmssUserTransferBuildExpectWrapper();

        $this->assertStringContainsString('env(PMSS_USER_TRANSFER_PASSWORD)', $script);
        $this->assertStringNotContainsString('send "{$', $script, 'expected password not embedded in script');
    }

    public function testGeneratedTransferScriptsMatchSnapshot(): void
    {
        $cfg = $this->baseConfig();
        $expectedScratchPaths = ['expect' => '/root/pmss-userTransfer-<generated>/transfer.expect', 'authProbe' => '/root/pmss-userTransfer-<generated>/auth-probe.sh', 'mainScript' => '/root/pmss-userTransfer-<generated>/rsync-main.sh', 'finalScript' => '/root/pmss-userTransfer-<generated>/rsync-final.sh', 'remoteSizeScript' => '/root/pmss-userTransfer-<generated>/remote-size.sh', 'qbittorrentProbeScript' => '/root/pmss-userTransfer-<generated>/qbittorrent-categories.sh', 'qbittorrentConfig' => '/root/pmss-userTransfer-<generated>/qBittorrent.conf', 'qbittorrentCategories' => '/root/pmss-userTransfer-<generated>/categories.json'];
        $expectedPayloadKeys = ['expect', 'authProbe', 'mainScript', 'finalScript', 'remoteSizeScript', 'qbittorrentProbeScript'];
        $expectedMain = <<<'SNAP'
#!/bin/bash
set -e
rsync -av -e 'ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -l '\''deefbox'\''' --exclude='.rtorrent.rc' --exclude='.config/qBittorrent/qBittorrent.conf' --exclude='.config/deluge/core.conf' --exclude='.config/deluge/web.conf' --exclude='.cache' --exclude='www' --exclude='session' --exclude='www/rutorrent/share' --exclude='.lighttpd' --exclude='.logs' --exclude='.local' --exclude='.lighttpd.conf' --exclude='.quota' --exclude='.rtorrentExecuteRun' --exclude='.trafficData' --exclude='.trafficDataLocal' --exclude='.trafficDataIngress' --exclude='.trafficDataIngressLocal' --exclude='rTorrentLog' --exclude='.bonusQuota' --exclude='.bonusTraffic' --exclude='.trafficLimit' 'deefbox@example.com:/home/deefbox/' '/home/deefbox/'
SNAP;
        $expectedFinal = <<<'SNAP'
#!/bin/bash
set -e
rsync -av -e 'ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -l '\''deefbox'\''' 'deefbox@example.com:/home/deefbox/session' 'deefbox@example.com:/home/deefbox/www/rutorrent/share' 'deefbox@example.com:/home/deefbox/.lighttpd/custom' 'deefbox@example.com:/home/deefbox/.lighttpd/custom.d' 'deefbox@example.com:/home/deefbox/.local' 'deefbox@example.com:/home/deefbox/www/public' '/home/deefbox/'
SNAP;
        $expectedAuth = <<<'SNAP'
#!/bin/bash
set -e
ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o ConnectTimeout=20 -o NumberOfPasswordPrompts=1 -l 'deefbox' 'example.com' '/bin/true'
SNAP;

        $this->assertEquals($expectedMain."\n", \pmssUserTransferBuildRsyncMain($cfg));
        $this->assertEquals($expectedFinal."\n", \pmssUserTransferBuildRsyncFinal($cfg));
        $this->assertEquals($expectedAuth."\n", \pmssUserTransferBuildAuthProbe($cfg));
        $this->assertSame($expectedScratchPaths, \pmssUserTransferScratchPaths('/root/pmss-userTransfer-<generated>/'));
        $this->assertSame($expectedPayloadKeys, array_keys(\pmssUserTransferScratchPayloads($cfg)));
    }

    public function testBuildQbittorrentCategoryProbeWritesRemoteMetadataToScratchFiles(): void
    {
        $script = \pmssUserTransferBuildQbittorrentCategoryProbe(
            $this->baseConfig(),
            '/root/scratch/qBittorrent.conf',
            '/root/scratch/categories.json'
        );

        $this->assertStringContainsAllStrings([
            "ssh -o Compression=no",
            "-o NumberOfPasswordPrompts=1",
            "-l 'deefbox'",
            "'example.com'",
            '/home/deefbox/.config/qBittorrent/qBittorrent.conf',
            '/home/deefbox/.config/qBittorrent/categories.json',
            "> '/root/scratch/qBittorrent.conf'",
            "> '/root/scratch/categories.json'",
        ], $script);
    }

    public function testLegacyQbittorrentCategoriesExtractsQtVariantMap(): void
    {
        $variant = $this->pmssQtSettingsEscapedBytes($this->pmssQtVariantStringMap([
            'Movies' => '/home/olduser/data/Movies',
            'sonarr' => '',
        ]));
        $config = "[Preferences]\nSession\\Categories=@Variant(ignored)\n[BitTorrent]\nSession\\Categories=@Variant({$variant})\n";

        $categories = \pmssUserTransferQbittorrentLegacyCategoriesFromConfig($config, [
            'localUser' => 'newuser',
            'remoteUser' => 'olduser',
        ]);

        $this->assertEquals([
            'Movies' => ['save_path' => '/home/newuser/data/Movies'],
            'sonarr' => ['save_path' => ''],
        ], $categories);
    }

    public function testLegacyQbittorrentCategoriesRejectsMalformedVariant(): void
    {
        $this->assertEquals([], \pmssUserTransferQbittorrentLegacyCategoriesFromConfig(
            "[BitTorrent]\nSession\\Categories=@Variant(garbage)\n",
            $this->baseConfig()
        ));
    }

    public function testQbittorrentCategoriesMergeWritesJsonWithoutOverwritingExisting(): void
    {
        $target = $this->pmssMakeTempPath('pmss-userTransfer-qbit-categories-').'/categories.json';
        $added = \pmssUserTransferQbittorrentCategoriesMerge($target, [
            'Movies' => ['save_path' => '/existing'],
        ], [
            'Movies' => ['save_path' => '/source'],
            'Books' => ['savePath' => '/books'],
        ]);

        $decoded = json_decode((string) file_get_contents($target), true);

        $this->assertEquals(1, $added);
        $this->assertEquals('/existing', $decoded['Movies']['save_path']);
        $this->assertEquals('/books', $decoded['Books']['save_path']);
        $this->assertTrue(!isset($decoded['Books']['savePath']), 'expected API-style savePath key to be normalized');
    }

    public function testQbittorrentCategoriesJsonReadRejectsInvalidJson(): void
    {
        $path = $this->pmssMakeTempPath('pmss-userTransfer-qbit-invalid-');
        file_put_contents($path, '{not json');

        $this->assertSame(null, \pmssUserTransferQbittorrentCategoriesJsonRead($path));
    }

    public function testSharedRsyncCommandBuilderMatchesSnapshot(): void
    {
        $cfg = $this->baseConfig();
        $expected = <<<'SNAP'
#!/bin/bash
set -e
rsync -av -e 'ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -l '\''deefbox'\''' --exclude='www' --exclude='session' 'deefbox@example.com:/home/deefbox/' 'deefbox@example.com:/home/deefbox/.local' '/home/deefbox/'
SNAP;

        $this->assertEquals(
            $expected."\n",
            \pmssUserTransferBuildRsyncCommand($cfg, ['/home/deefbox/', '/home/deefbox/.local'], ['www', 'session'])
        );
    }

    public function testRewriteBencodedHomePathsAdjustsStringLengths(): void
    {
        $oldPath = '/home/olduser/data/movie';
        $payload = 'd9:directory'.strlen($oldPath).':'.$oldPath.'4:name4:teste';

        $replacements = 0;
        $rewritten = \pmssUserTransferRewriteBencodedHomePaths($payload, 'olduser', 'new', $replacements);
        $newPath = '/home/new/data/movie';
        $expected = 'd9:directory'.strlen($newPath).':'.$newPath.'4:name4:teste';

        $this->assertTrue($rewritten !== null, 'expected valid bencode payload rewrite');
        $this->assertEquals(1, $replacements);
        $this->assertEquals($expected, $rewritten);
    }

    public function testRewriteBencodedHomePathsReturnsInputWhenNoPathMatch(): void
    {
        $path = '/home/another/data';
        $payload = 'd9:directory'.strlen($path).':'.$path.'e';

        $replacements = 0;
        $rewritten = \pmssUserTransferRewriteBencodedHomePaths($payload, 'olduser', 'newuser', $replacements);

        $this->assertTrue($rewritten !== null, 'expected valid payload to remain valid');
        $this->assertEquals(0, $replacements);
        $this->assertEquals($payload, $rewritten);
    }

    public function testRewriteBencodedHomePathsRejectsMalformedInput(): void
    {
        $malformed = 'd9:directory999:/home/olduser/datae';
        $replacements = 0;

        $rewritten = \pmssUserTransferRewriteBencodedHomePaths($malformed, 'olduser', 'newuser', $replacements);

        $this->assertTrue($rewritten === null, 'expected malformed payload to be rejected');
        $this->assertEquals(0, $replacements);
    }

    public function testRewriteRtorrentSessionPathsUpdatesSessionFilesForUserRename(): void
    {
        $home = $this->pmssMakeUserHomeTree('pmss-userTransfer-session-rewrite-', 'session', 'home/newuser');
        $sessionDir = $home.'/session';

        $sessionFile = $sessionDir.'/test.torrent.rtorrent';
        $oldPath = '/home/olduser/data/movie';
        $payload = 'd9:directory'.strlen($oldPath).':'.$oldPath.'e';
        file_put_contents($sessionFile, $payload);

        \pmssUserTransferRewriteRtorrentSessionPaths([
            'localUser' => 'newuser',
            'remoteUser' => 'olduser',
        ], $home);

        $updated = (string) file_get_contents($sessionFile);
        $newPath = '/home/newuser/data/movie';
        $expected = 'd9:directory'.strlen($newPath).':'.$newPath.'e';

        $this->assertEquals($expected, $updated);
    }

    public function testRewriteRtorrentSessionPathsReportsWhenNothingNeedsRewrite(): void
    {
        $base = $this->pmssMakeTempDir('pmss-userTransfer-session-nochange-');
        $home = $base.'/home/newuser';
        $sessionDir = $home.'/session';
        $logDir = $base.'/logs';
        $this->pmssEnsureDir($sessionDir);
        $this->pmssEnsureDir($logDir);

        $path = '/home/another/data/movie';
        file_put_contents($sessionDir.'/test.torrent.rtorrent', 'd9:directory'.strlen($path).':'.$path.'e');

        $output = '';
        $this->pmssWithEnv(['PMSS_LOG_DIR' => $logDir], function () use ($home, &$output): void {
            list(, $output) = $this->pmssCaptureStdout(function () use ($home): void {
                \pmssUserTransferRewriteRtorrentSessionPaths([
                    'localUser' => 'newuser',
                    'remoteUser' => 'olduser',
                ], $home);
            });
        });

        $this->assertStringContainsString('[INFO] rTorrent session rewrite found no /home path references to update', $output);
    }

    public function testIsPathWithinHomeAcceptsRealChildPaths(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-userTransfer-path-within-', 'testuser');
        $this->pmssWriteFile($home.'/www/index.html', 'x');

        $this->assertTrue(\pmssUserTransferIsPathWithinHome($home.'/www', $home), 'expected www dir within home');
        $this->assertTrue(\pmssUserTransferIsPathWithinHome($home.'/www/index.html', $home), 'expected file within home');
    }

    public function testIsPathWithinHomeRejectsSymlinkEscapes(): void
    {
        $base = $this->pmssMakeTempDir('pmss-userTransfer-path-escape-');
        $home = $base.'/home/testuser';
        $outside = $base.'/outside';
        $this->pmssEnsureDir($home);
        $this->pmssEnsureDir($outside);
        file_put_contents($outside.'/secret', 'x');

        if (!function_exists('symlink') || @symlink($outside, $home.'/escape') === false) {
            throw new SkipTest('symlink not supported in this environment');
        }

        $this->assertTrue(
            !\pmssUserTransferIsPathWithinHome($home.'/escape/secret', $home),
            'expected symlink target to be rejected as outside home'
        );
    }

    public function testUserTransferHomePathsStillResolveFromPmssHomeDirEnv(): void
    {
        $helper = $this->pmssReadRepoFile('scripts/lib/userTransfer/localUserSafety.php');

        $needle = "pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home')";
        $this->assertStringContainsString($needle, $helper, 'userTransfer safety checks must honour PMSS_HOME_DIR');
    }

    public function testWriteFilePersistsPayloadAndMode(): void
    {
        $path = $this->pmssMakeTempPath('pmss-userTransfer-write-');
        \pmssUserTransferWriteFile($path, 'payload', 0600);

        $this->assertEquals('payload', (string) file_get_contents($path));
        $this->assertEquals(0600, fileperms($path) & 0777);
    }

    public function testWriteFileThrowsWhenParentDirectoryIsMissing(): void
    {
        $path = $this->pmssMakeTempPath('pmss-userTransfer-missing-').'/payload';

        $this->assertThrowsRuntime(static function () use ($path): void {
            \pmssUserTransferWriteFile($path, 'payload', 0600);
        }, 'Failed writing: ');
    }

    public function testWriteFileRejectsSymlinkTarget(): void
    {
        $base = $this->pmssMakeTempDir('pmss-userTransfer-symlink-');
        $realTarget = $base.'/real';
        $target = $base.'/link';
        file_put_contents($realTarget, 'original');
        symlink($realTarget, $target);

        $this->assertThrowsRuntime(static function () use ($target): void {
            \pmssUserTransferWriteFile($target, 'payload', 0600);
        }, 'Failed writing: ');

        $this->assertEquals('original', (string) file_get_contents($realTarget));
    }

    public function testSleepReturnsImmediatelyWhenNoLiveDelayIsAllowed(): void
    {
        foreach ([[1, 1, true, 'dry-run'], [0, 0, false, 'non-positive max']] as [$min, $max, $dryRun, $label]) {
            putenv($dryRun ? 'PMSS_DRY_RUN=1' : 'PMSS_DRY_RUN');
            $started = microtime(true);
            \pmssUserTransferSleep($min, $max, 'Unit test');
            $this->assertTrue((microtime(true) - $started) < 0.2, 'expected '.$label.' sleep to return immediately');
        }
    }

    private function baseConfig(array $overrides = []): array
    {
        return array_replace(['localUser' => 'deefbox', 'remoteUser' => 'deefbox', 'hostname' => 'example.com'], $overrides);
    }

    private function assertHostnameValidity(array $hostnames, bool $expected, string $label): void
    {
        foreach ($hostnames as $hostname) {
            $this->assertSame($expected, \pmssHostnameIsValid($hostname), 'expected '.$label.' hostname: '.$hostname);
        }
    }

    private function pmssQtVariantStringMap(array $values): string
    {
        $bytes = pack('N', 8).pack('N', count($values));
        foreach ($values as $key => $value) {
            $bytes .= $this->pmssQtString((string) $key);
            $bytes .= pack('N', 10);
            $bytes .= $this->pmssQtString((string) $value);
        }
        return $bytes;
    }

    private function pmssQtString(string $value): string
    {
        $raw = '';
        for ($i = 0; $i < strlen($value); $i++) {
            $raw .= "\0".$value[$i];
        }
        return pack('N', strlen($raw)).$raw;
    }

    private function pmssQtSettingsEscapedBytes(string $bytes): string
    {
        $escaped = '';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $ord = ord($bytes[$i]);
            if ($ord === 0) {
                $escaped .= '\0';
            } elseif ($ord === 10) {
                $escaped .= '\n';
            } elseif ($ord >= 32 && $ord <= 126 && $bytes[$i] !== '\\') {
                $escaped .= $bytes[$i];
            } else {
                $escaped .= '\x'.str_pad(dechex($ord), 2, '0', STR_PAD_LEFT);
            }
        }
        return $escaped;
    }
}
