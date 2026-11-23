<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/configRender.php';

/**
 * Per-account opt-in for buffered proxy responses (server.stream-response-body = 1).
 *
 * The host default stays 2 (stream without spooling to the account's upload dir). An account
 * that creates the marker `~/.lighttpd/stream-response-body.buffered` renders 1 for itself only;
 * the renderer runs as root and the marker is account-writable, so it tests existence only and
 * never reads the marker's content.
 */
final class LighttpdStreamResponseBodyOptInTest extends TestCase
{
    private const MARKER = '.lighttpd/stream-response-body.buffered';

    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-stream-optin-test', 0700]; }

    /** Render $user's config with homes resolved under the test's temp dir. */
    private function render(string $user = 'fixtur01'): string
    {
        $rendered = '';
        $this->pmssWithEnv(['PMSS_HOME_DIR' => $this->tempDir], function () use (&$rendered, $user): void {
            $rendered = \pmssLighttpdRenderUserConfig($this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd'),
                $user, 30000, 0, 0, ['maxProcs' => 2, 'children' => 2], [], false);
        });
        return $rendered;
    }

    /** Create the marker for $user; $content is written verbatim, $asDir makes a directory instead. */
    private function mark(string $user, string $content = '', bool $asDir = false): void
    {
        $dir = $this->tempDir.'/'.$user.'/.lighttpd';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $this->tempDir.'/'.$user.'/'.self::MARKER;
        $asDir ? mkdir($path, 0700) : file_put_contents($path, $content);
    }

    /** Values of every server.stream-response-body assignment in the config. */
    private function responseModes(string $config): array
    {
        preg_match_all('/^\s*server\.stream-response-body\s*=\s*(\S+)/m', $config, $m);
        return $m[1];
    }

    public function testDefaultStaysStreamedWithoutSpooling(): void
    {
        $config = $this->render();
        $this->assertSame(['2'], $this->responseModes($config));
        $this->assertStringContainsString('server.stream-request-body = 2', $config);
    }

    public function testMarkerSwitchesOnlyTheResponseBodyToBuffered(): void
    {
        $this->mark('fixtur01');
        $config = $this->render();
        $this->assertSame(['1'], $this->responseModes($config));
        // The request side (uploads, WebDAV PUT) keeps its streaming mode.
        $this->assertStringContainsString('server.stream-request-body = 2', $config);
    }

    public function testMarkerContentIsNeverRead(): void
    {
        // Account-writable content must not reach the root-rendered config, whatever it says.
        $this->mark('fixtur01', "server.stream-response-body = 0\n9\n\$HTTP[\"url\"] =~ \".\" { url.access-deny = ( \"\" ) }\n");
        $config = $this->render();
        $this->assertSame(['1'], $this->responseModes($config));
        $this->assertStringNotContainsString('url.access-deny = ( "" ) }', $config);
    }

    public function testMarkerOfAnotherAccountDoesNotApply(): void
    {
        $this->mark('fixtur02');
        $this->assertSame(['2'], $this->responseModes($this->render('fixtur01')));
        $this->assertSame(['1'], $this->responseModes($this->render('fixtur02')));
    }

    public function testDirectoryOrMissingHomeFallsBackToTheDefault(): void
    {
        // A directory of that name is not the marker; no home at all is the plain default.
        $this->mark('fixtur01', '', true);
        $this->assertSame(['2'], $this->responseModes($this->render()));
        $this->assertSame(['2'], $this->responseModes($this->render('fixtur03')));
    }

    public function testLinksAreNeverFollowedSoTheAccountCannotProbeRootPaths(): void
    {
        // A link to any existing file would make an existence oracle for root-only paths:
        // the account could read the answer from its rendered config.
        $this->mark('fixtur01');
        $path = $this->tempDir.'/fixtur01/'.self::MARKER;
        unlink($path);
        symlink(__FILE__, $path);
        $this->assertSame(['2'], $this->responseModes($this->render()));
        unlink($path);
        $real = $this->tempDir.'/elsewhere';
        mkdir($real, 0700);
        touch($real.'/stream-response-body.buffered');
        rmdir($this->tempDir.'/fixtur01/.lighttpd');
        symlink($real, $this->tempDir.'/fixtur01/.lighttpd');
        $this->assertSame(['2'], $this->responseModes($this->render()));
    }
    public function testRenderIsIdempotentAndReversibleWhenTheMarkerGoes(): void
    {
        $this->mark('fixtur01');
        $on = $this->render();
        $this->assertSame(['1'], $this->responseModes($on));
        $this->assertSame($on, $this->render());
        unlink($this->tempDir.'/fixtur01/'.self::MARKER);
        $this->assertSame(['2'], $this->responseModes($this->render()));
    }

    public function testInvalidUsernameNeverTouchesTheFilesystemPath(): void
    {
        // A traversal-shaped name would resolve to fixtur01's real marker; it must not apply.
        $this->mark('fixtur01');
        mkdir($this->tempDir.'/hop', 0700);
        $this->assertSame(['1'], $this->responseModes($this->render('fixtur01')));
        $this->assertSame(['2'], $this->responseModes($this->render('hop/../fixtur01')));
    }

    public function testMarkerChangesExactlyOneLineOfAnExistingAccountsConfig(): void
    {
        // Upgrade story: an existing account renders as before; opting in moves only that value.
        $before = explode("\n", $this->render());
        $this->mark('fixtur01');
        $after = explode("\n", $this->render());
        $this->assertSame(count($before), count($after));
        $changed = array_keys(array_diff_assoc($before, $after));
        $this->assertSame(1, count($changed));
        $this->assertMatches('/^\s*server\.stream-response-body\s*=\s*2$/', $before[$changed[0]]);
        $this->assertMatches('/^\s*server\.stream-response-body\s*=\s*1$/', $after[$changed[0]]);
    }

    public function testWebdavMarkerAloneDoesNotBufferResponses(): void
    {
        $dir = $this->tempDir.'/fixtur01/.lighttpd';
        mkdir($dir, 0700, true);
        touch($dir.'/webdav.www-writable');
        $this->assertSame(['2'], $this->responseModes($this->render()));
    }

    public function testBothMarkersTogetherKeepTheWebdavPolicyAndBufferResponses(): void
    {
        $this->mark('fixtur01');
        touch($this->tempDir.'/fixtur01/.lighttpd/webdav.www-writable');
        $config = $this->render();
        $this->assertSame(['1'], $this->responseModes($config));
        $this->assertStringContainsString('webdav.is-readonly = "disable"', $config);
    }

    public function testMarkerOutsideTheLighttpdDirectoryDoesNotApply(): void
    {
        // The documented place is ~/.lighttpd/; the same name in the home root or www is just a file.
        mkdir($this->tempDir.'/fixtur01/www/public', 0700, true);
        touch($this->tempDir.'/fixtur01/stream-response-body.buffered');
        touch($this->tempDir.'/fixtur01/www/public/stream-response-body.buffered');
        $this->assertSame(['2'], $this->responseModes($this->render()));
    }

    public function testTemplateKeepsTheDocumentedDefaultAndContract(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.lighttpd',
            ['server.stream-response-body = 2', 'stream-response-body.buffered']);
        $this->pmssAssertRepoFileContainsAllStrings('docs/contracts.md', ['stream-response-body.buffered']);
    }
}
