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

    public function testBuildExpectWrapperUsesEnvPassword(): void
    {
        $script = \pmssUserTransferBuildExpectWrapper();

        $this->assertStringContainsString('env(PMSS_USER_TRANSFER_PASSWORD)', $script);
        $this->assertTrue(strpos($script, 'send "{$') === false, 'expected password not embedded in script');
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

    public function testHomeRootDefaultsToHomeWhenEnvUnset(): void
    {
        putenv('PMSS_HOME_DIR');
        $this->assertEquals('/home', \pmssUserTransferHomeRoot());
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
