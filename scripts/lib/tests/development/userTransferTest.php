<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/userTransfer.php';

/**
 * Hermetic tests for user transfer helpers.
 */
class UserTransferTest extends TestCase
{
    public function tearDown(): void
    {
        putenv('PMSS_HOME_DIR');
        putenv('PMSS_DRY_RUN');
        putenv('PMSS_LOG_DIR');
    }

    public function testHostnameValidationAcceptsExpectedNames(): void
    {
        $valid = [
            'lt5-1-56-138anger-core.pulsedmedia.com',
            'le4-0-78-95wheatley.pulsedmedia.com',
            '185.148.1.138',
            'a.b',
            'abc123.example',
            'a-b.c-d',
        ];

        foreach ($valid as $hostname) {
            $this->assertTrue(
                \pmssUserTransferHostnameIsValid($hostname),
                'expected hostname to be valid: '.$hostname
            );
        }
    }

    public function testHostnameValidationRejectsBadNames(): void
    {
        $invalid = [
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
        ];

        foreach ($invalid as $hostname) {
            $this->assertTrue(
                !\pmssUserTransferHostnameIsValid($hostname),
                'expected hostname to be invalid: '.$hostname
            );
        }
    }

    public function testParseCliTwoArgsAppendsSuffix(): void
    {
        $cfg = \pmssUserTransferParseCli(['userTransfer.php', 'deefbox', 'lt5-1-56-138anger-core']);

        $this->assertEquals('deefbox', $cfg['localUser']);
        $this->assertEquals('deefbox', $cfg['remoteUser']);
        $this->assertEquals('lt5-1-56-138anger-core.pulsedmedia.com', $cfg['hostname']);
        $this->assertTrue($cfg['suffixAppended'], 'expected suffix appended');
    }

    public function testParseCliThreeArgsKeepsRemoteUser(): void
    {
        $cfg = \pmssUserTransferParseCli(['userTransfer.php', 'deefbox', 'remote01', 'example.com']);

        $this->assertEquals('deefbox', $cfg['localUser']);
        $this->assertEquals('remote01', $cfg['remoteUser']);
        $this->assertEquals('example.com', $cfg['hostname']);
        $this->assertTrue(!$cfg['suffixAppended'], 'expected no suffix appended');
    }

    public function testParseCliNormalisesUsernamesToLowercase(): void
    {
        $cfg = \pmssUserTransferParseCli(['userTransfer.php', 'DeefBox', 'example.com']);
        $this->assertEquals('deefbox', $cfg['localUser']);
        $this->assertEquals('deefbox', $cfg['remoteUser']);
    }

    public function testParseCliRejectsInvalidUsernames(): void
    {
        $this->assertThrowsRuntime(static function (): void {
            \pmssUserTransferParseCli(['userTransfer.php', 'deef_box', 'example.com']);
        }, 'Invalid username');

        $this->assertThrowsRuntime(static function (): void {
            \pmssUserTransferParseCli(['userTransfer.php', 'toolongname', 'example.com']);
        }, 'Invalid username');
    }

    public function testParseCliRejectsInvalidHostname(): void
    {
        $this->assertThrowsRuntime(static function (): void {
            \pmssUserTransferParseCli(['userTransfer.php', 'deefbox', 'bad host']);
        }, 'Invalid hostname');
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

    public function testParseCliRejectsMissingOptionValue(): void
    {
        $this->assertThrowsRuntime(static function (): void {
            \pmssUserTransferParseCli(['userTransfer.php', 'deefbox', 'example.com', '--main-passes']);
        }, 'requires a value');
    }

    public function testParseCliRejectsNonIntegerOptionValue(): void
    {
        $this->assertThrowsRuntime(static function (): void {
            \pmssUserTransferParseCli(['userTransfer.php', '--sleep-min=abc', 'deefbox', 'example.com']);
        }, 'expected integer');
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
    }

    public function testParseCliStopsOptionParsingAfterDoubleDash(): void
    {
        $cfg = \pmssUserTransferParseCli(['userTransfer.php', '--', 'deefbox', 'example.com']);

        $this->assertEquals('deefbox', $cfg['localUser']);
        $this->assertEquals('deefbox', $cfg['remoteUser']);
        $this->assertEquals('example.com', $cfg['hostname']);
    }

    public function testParseCliNoSleepOverridesSleepRange(): void
    {
        $cfg = \pmssUserTransferParseCli(['userTransfer.php', '--no-sleep', 'deefbox', 'example.com']);
        $this->assertEquals(0, $cfg['sleepMin']);
        $this->assertEquals(0, $cfg['sleepMax']);
    }

    public function testParseCliRejectsSleepRangeInversion(): void
    {
        $this->assertThrowsRuntime(static function (): void {
            \pmssUserTransferParseCli(['userTransfer.php', '--sleep-min=10', '--sleep-max=5', 'deefbox', 'example.com']);
        }, 'sleep-max must be');
    }

    public function testParseCliRejectsExcessivePasses(): void
    {
        $this->assertThrowsRuntime(static function (): void {
            \pmssUserTransferParseCli(['userTransfer.php', '--main-passes=501', 'deefbox', 'example.com']);
        }, 'main-passes');
    }

    public function testBuildRsyncMainUsesTrailingSlashAndExcludes(): void
    {
        $cfg = ['localUser' => 'deefbox', 'remoteUser' => 'deefbox', 'hostname' => 'example.com'];
        $script = \pmssUserTransferBuildRsyncMain($cfg);

        $this->assertStringContainsString('rsync -av', $script);
        $this->assertStringContainsString(':/home/deefbox/', $script);
        $this->assertStringContainsString('/home/deefbox/', $script);
        $this->assertStringContainsString("--exclude='.rtorrent.rc'", $script);
        $this->assertStringContainsString("--exclude='.trafficDataIngress'", $script);
        $this->assertStringContainsString("--exclude='.trafficDataIngressLocal'", $script);
        $this->assertTrue(strpos($script, '--exclude={') === false, 'expected no brace-expanded excludes');
    }

    public function testBuildRsyncFinalUsesExplicitSources(): void
    {
        $cfg = ['localUser' => 'deefbox', 'remoteUser' => 'deefbox', 'hostname' => 'example.com'];
        $script = \pmssUserTransferBuildRsyncFinal($cfg);

        $this->assertStringContainsString('rsync -av', $script);
        $this->assertStringContainsString(':/home/deefbox/session', $script);
        $this->assertStringContainsString(':/home/deefbox/www/public', $script);
        $this->assertTrue(strpos($script, '{session') === false, 'expected no brace-expanded sources');
    }

    public function testBuildAuthProbeUsesSinglePasswordPrompt(): void
    {
        $cfg = ['localUser' => 'deefbox', 'remoteUser' => 'deefbox', 'hostname' => 'example.com'];
        $script = \pmssUserTransferBuildAuthProbe($cfg);

        $this->assertStringContainsString('ssh -o Compression=no', $script);
        $this->assertStringContainsString('-o NumberOfPasswordPrompts=1', $script);
        $this->assertStringContainsString("-l 'deefbox'", $script);
        $this->assertStringContainsString("'example.com'", $script);
        $this->assertStringContainsString("'/bin/true'", $script);
    }

    public function testGeneratedScriptsKeepSharedSshFlagsAligned(): void
    {
        $cfg = ['localUser' => 'deefbox', 'remoteUser' => 'deefbox', 'hostname' => 'example.com'];
        $sharedFlags = 'ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no';

        $this->assertStringContainsString($sharedFlags, \pmssUserTransferBuildRsyncMain($cfg));
        $this->assertStringContainsString($sharedFlags, \pmssUserTransferBuildRsyncFinal($cfg));
        $this->assertStringContainsString($sharedFlags, \pmssUserTransferBuildAuthProbe($cfg));
    }

    public function testBuildExpectWrapperUsesEnvPassword(): void
    {
        $script = \pmssUserTransferBuildExpectWrapper();

        $this->assertStringContainsString('env(PMSS_USER_TRANSFER_PASSWORD)', $script);
        $this->assertTrue(strpos($script, 'send "{$') === false, 'expected password not embedded in script');
    }

    public function testGeneratedTransferScriptsMatchSnapshot(): void
    {
        $cfg = ['localUser' => 'deefbox', 'remoteUser' => 'deefbox', 'hostname' => 'example.com'];
        $expectedMain = <<<'SNAP'
#!/bin/bash
set -e
rsync -av -e 'ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -l '\''deefbox'\''' --exclude='.rtorrent.rc' --exclude='.config/qBittorrent/qBittorrent.conf' --exclude='.config/deluge/core.conf' --exclude='.config/deluge/web.conf' --exclude='.cache' --exclude='www' --exclude='session' --exclude='www/rutorrent/share' --exclude='.lighttpd' --exclude='.logs' --exclude='.local' --exclude='.lighttpd.conf' --exclude='.quota' --exclude='.rtorrentExecuteRun' --exclude='.trafficData' --exclude='.trafficDataLocal' --exclude='.trafficDataIngress' --exclude='.trafficDataIngressLocal' --exclude='rTorrentLog' --exclude='.bonusQuota' --exclude='.bonusTraffic' --exclude='.billingId' --exclude='.trafficLimit' 'deefbox@example.com:/home/deefbox/' '/home/deefbox/'
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
    }

    public function testSharedRsyncCommandBuilderMatchesSnapshot(): void
    {
        $cfg = ['localUser' => 'deefbox', 'remoteUser' => 'deefbox', 'hostname' => 'example.com'];
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
        $base = sys_get_temp_dir().'/pmss-userTransfer-session-rewrite-'.uniqid('', true);
        $home = $base.'/home/newuser';
        $sessionDir = $home.'/session';
        @mkdir($sessionDir, 0755, true);

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
        $base = sys_get_temp_dir().'/pmss-userTransfer-session-nochange-'.uniqid('', true);
        $home = $base.'/home/newuser';
        $sessionDir = $home.'/session';
        $logDir = $base.'/logs';
        @mkdir($sessionDir, 0755, true);
        @mkdir($logDir, 0755, true);

        $path = '/home/another/data/movie';
        file_put_contents($sessionDir.'/test.torrent.rtorrent', 'd9:directory'.strlen($path).':'.$path.'e');
        putenv('PMSS_LOG_DIR='.$logDir);

        ob_start();
        \pmssUserTransferRewriteRtorrentSessionPaths([
            'localUser' => 'newuser',
            'remoteUser' => 'olduser',
        ], $home);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('[INFO] rTorrent session rewrite found no /home path references to update', $output);
    }

    public function testIsPathWithinHomeAcceptsRealChildPaths(): void
    {
        $base = sys_get_temp_dir().'/pmss-userTransfer-path-within-'.uniqid('', true);
        $home = $base.'/home/testuser';
        @mkdir($home.'/www', 0755, true);
        file_put_contents($home.'/www/index.html', 'x');

        $this->assertTrue(\pmssUserTransferIsPathWithinHome($home.'/www', $home), 'expected www dir within home');
        $this->assertTrue(\pmssUserTransferIsPathWithinHome($home.'/www/index.html', $home), 'expected file within home');
    }

    public function testIsPathWithinHomeRejectsSymlinkEscapes(): void
    {
        $base = sys_get_temp_dir().'/pmss-userTransfer-path-escape-'.uniqid('', true);
        $home = $base.'/home/testuser';
        $outside = $base.'/outside';
        @mkdir($home, 0755, true);
        @mkdir($outside, 0755, true);
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
        $helper = (string) file_get_contents(dirname(__DIR__, 2).'/userTransfer/localUserSafety.php');

        $needle = "pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home')";
        $this->assertTrue(strpos($helper, $needle) !== false, 'userTransfer safety checks must honour PMSS_HOME_DIR');
    }

    public function testWriteFilePersistsPayloadAndMode(): void
    {
        $path = sys_get_temp_dir().'/pmss-userTransfer-write-'.uniqid('', true);
        \pmssUserTransferWriteFile($path, 'payload', 0600);

        $this->assertEquals('payload', (string) file_get_contents($path));
        $this->assertEquals(0600, fileperms($path) & 0777);
    }

    public function testWriteFileThrowsWhenParentDirectoryIsMissing(): void
    {
        $path = sys_get_temp_dir().'/pmss-userTransfer-missing-'.uniqid('', true).'/payload';

        $this->assertThrowsRuntime(static function () use ($path): void {
            \pmssUserTransferWriteFile($path, 'payload', 0600);
        }, 'Failed writing: ');
    }

    public function testWriteFileRejectsSymlinkTarget(): void
    {
        $base = sys_get_temp_dir().'/pmss-userTransfer-symlink-'.uniqid('', true);
        $realTarget = $base.'-real';
        $target = $base.'-link';
        file_put_contents($realTarget, 'original');
        symlink($realTarget, $target);

        try {
            $this->assertThrowsRuntime(static function () use ($target): void {
                \pmssUserTransferWriteFile($target, 'payload', 0600);
            }, 'Failed writing: ');

            $this->assertEquals('original', (string) file_get_contents($realTarget));
        } finally {
            if (is_link($target) || file_exists($target)) {
                @unlink($target);
            }
            if (file_exists($realTarget)) {
                @unlink($realTarget);
            }
        }
    }

    public function testSleepReturnsImmediatelyDuringDryRun(): void
    {
        putenv('PMSS_DRY_RUN=1');

        $started = microtime(true);
        \pmssUserTransferSleep(1, 1, 'Unit test');

        $this->assertTrue((microtime(true) - $started) < 0.2, 'expected dry-run sleep to return immediately');
    }

    public function testSleepReturnsImmediatelyWhenMaximumIsNonPositive(): void
    {
        $started = microtime(true);
        \pmssUserTransferSleep(0, 0, 'Unit test');

        $this->assertTrue((microtime(true) - $started) < 0.2, 'expected non-positive max sleep to return immediately');
    }

    private function assertThrowsRuntime(callable $fn, string $messageFragment): void
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($messageFragment, $e->getMessage());
            return;
        }
        throw new \AssertionError('Expected RuntimeException, none thrown');
    }
}
