<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Hermetic coverage for the legacy lighttpd migration helpers embedded in
 * etc/skel/install-media-stack.sh.
 */
class MediaStackLighttpdMigrationTest extends TestCase
{
    /** @var string */
    private $script;

    protected function setUp(): void
    {
        $this->script = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
    }

    public function testPrepareLighttpdRemovesManagedRoutesFromMigratedFragment(): void
    {
        $custom = implode("\n", [
            '$HTTP["url"] =~ "^/sabnzbd(\$|/)" {',
            '  proxy.server = ( "" => ( (',
            '    "host" => "127.0.0.1",',
            '    "port" => 1234',
            '  ) ) )',
            '}',
            '$HTTP["url"] =~ "^/custom(\$|/)" {',
            '  proxy.server = ( "" => ( (',
            '    "host" => "127.0.0.1",',
            '    "port" => 8080',
            '  ) ) )',
            '}',
            '$HTTP["url"] =~ "^/radarr(\$|/)" {',
            '  proxy.server = ( "" => ( (',
            '    "host" => "127.0.0.1",',
            '    "port" => 5678',
            '  ) ) )',
            '}',
            '',
        ]);

        $home = $this->pmssRunPrepareLighttpd($custom);
        $migrated = (string) file_get_contents($home.'/.lighttpd/custom.d/custom-migrated.conf');

        $this->assertStringContainsAndOmitsStrings([], ['^/sabnzbd(\$|/)', '^/radarr(\$|/)'], $migrated);
        $this->assertStringContainsString('^/custom(\$|/)', $migrated);
        $this->assertSame('', (string) file_get_contents($home.'/.lighttpd/custom'));
    }

    public function testPrepareLighttpdPreservesNonOverlappingCustomContent(): void
    {
        $custom = implode("\n", [
            '# user-owned rules stay intact',
            '$HTTP["url"] =~ "^/custom(\$|/)" {',
            '  setenv.add-response-header = ( "X-Test" => "yes" )',
            '}',
            '$HTTP["url"] =~ "^/public-fixtur01/sonarr/" {',
            '  setenv.add-response-header = ( "X-Customer" => "preserved" )',
            '}',
            '',
        ]);

        $home = $this->pmssRunPrepareLighttpd($custom);
        $migrated = (string) file_get_contents($home.'/.lighttpd/custom.d/custom-migrated.conf');

        $this->assertStringContainsAllStrings(['# user-owned rules stay intact', '^/custom(\$|/)'], $migrated);
        $this->assertSame($custom, $migrated);
    }

    public function testPrepareLighttpdBlanksLegacyManagedFileInsteadOfMigrating(): void
    {
        $legacy = implode("\n", [
            '# Keep ARR base paths canonical so missing-slash requests',
            '$HTTP["url"] =~ "^/sabnzbd(\$|/)" {',
            '$HTTP["url"] =~ "^/radarr(\$|/)" {',
            '$HTTP["url"] =~ "^/prowlarr(\$|/)" {',
            '$HTTP["url"] =~ "^/sonarr(\$|/)" {',
            '$HTTP["url"] =~ "^/jellyfin(\$|/)" {',
            '',
        ]);

        $home = $this->pmssRunPrepareLighttpd($legacy);

        $this->assertFalse(is_file($home.'/.lighttpd/custom.d/custom-migrated.conf'));
        $this->assertSame('', (string) file_get_contents($home.'/.lighttpd/custom'));
    }

    public function testPrepareLighttpdLeavesCustomWhenMigratedFragmentAlreadyExists(): void
    {
        $custom = "# current custom\n";
        $existing = "# existing migrated\n";
        $home = $this->pmssRunPrepareLighttpd($custom, $existing);

        $this->assertSame($custom, (string) file_get_contents($home.'/.lighttpd/custom'));
        $this->assertSame($existing, (string) file_get_contents($home.'/.lighttpd/custom.d/custom-migrated.conf'));
    }

    public function testPrepareLighttpdCreatesBlankCustomWhenMissing(): void
    {
        $home = $this->pmssRunPrepareLighttpd(null);

        $this->assertTrue(is_file($home.'/.lighttpd/custom'));
        $this->assertSame('', (string) file_get_contents($home.'/.lighttpd/custom'));
    }

    public function testStripRecognizesPanelAppPathsAndIsIdempotent(): void
    {
        $fragment = $this->pmssMakeTempDir('pmss-media-routes-').'/routes.conf';
        $unrelated = "# preserved\n".'$HTTP["url"] =~ "^/sonarrival/" { }'."\n";
        $contents = $unrelated;
        foreach (['sabnzbd', 'radarr', 'prowlarr', 'sonarr', 'jellyfin', 'autobrr'] as $app) {
            foreach (['^/'.$app.'(\$|/)', '^/public-fixtur01/'.$app.'($|/)',
                '^/user-fixtur01/'.$app.'(/|$)', '/user-fixtur01/apps/'.$app.'/', '/'.$app] as $path) {
                $contents .= '$HTTP["url"] =~ "'.$path.'" { proxy.server = () }'."\n";
            }
        }
        $contents .= '$HTTP ["url"] == "/sonarr/" { }'."\n"; // Unrecognized syntax stays intact.
        $unrelated .= '$HTTP ["url"] == "/sonarr/" { }'."\n";
        $contents .= '$HTTP[ "url" ] == "/JELLYFIN/" { }'."\n";
        $this->pmssWriteFile($fragment, $contents);
        chmod($fragment, 0600);

        $this->pmssRunRouteStrip($fragment);
        $this->assertSame($unrelated, file_get_contents($fragment));
        clearstatcache(true, $fragment);
        $this->assertSame(0600, fileperms($fragment) & 0777);
        $this->pmssRunRouteStrip($fragment);
        $this->assertSame($unrelated, file_get_contents($fragment));
    }

    public function testStripPreservesNestedUnrelatedRulesAndIgnoresQuotedBraces(): void
    {
        $fragment = $this->pmssMakeTempDir('pmss-media-routes-').'/routes.conf';
        $before = '$HTTP["host"] == "fixture.invalid" {'."\n";
        $after = '  $HTTP["url"] =~ "^/custom/" { }'."\n}\n";
        $route = <<<'LIGHTTPD'
  $HTTP["url"] =~ "^/sonarr/" { # } does not close the route
    setenv.add-response-header = ( "X-Fixture" => "escaped \" } { #" )
    $HTTP["request-method"] == "GET" {
      proxy.server = ( "" => (( "host" => "127.0.0.1", "port" => 12345 )) )
    }
  }
LIGHTTPD;
        $this->pmssWriteFile($fragment, $before.$route."\n".$after);

        $this->pmssRunRouteStrip($fragment);
        $this->assertSame($before.$after, file_get_contents($fragment));
    }

    public function testStripRefusesIncompleteBlocksAndAmbiguousElseBranches(): void
    {
        $fragment = $this->pmssMakeTempDir('pmss-media-routes-').'/routes.conf';
        foreach ([
            '$HTTP["url"] =~ "^/sonarr/" {',
            '$HTTP["url"] =~ "^/sonarr/" { } else { }',
            '$HTTP["url"] =~ "^/sonarr/" { }'."\n# comment\nelse { }\n",
            '$HTTP["url"] =~ "^/sonarr/" { } $HTTP["url"] =~ "^/custom/" { }',
            '$HTTP["url"] =~ "^/sonarr/"'."\n{ }\n",
            '$HTTP["url"] =~ "^/sonarr/|^/custom/" { }',
        ] as $contents) {
            $this->pmssWriteFile($fragment, $contents);
            $output = $this->pmssRunRouteStrip($fragment, 0, true);
            $this->assertStringContainsString('original preserved', $output);
            $this->assertSame($contents, file_get_contents($fragment));
            $this->assertSame([], glob($fragment.'.pmss.*'));
        }
    }

    public function testStripDryRunPreservesFragmentWithoutStagingFiles(): void
    {
        $fragment = $this->pmssMakeTempDir('pmss-media-routes-').'/routes.conf';
        $contents = '$HTTP["url"] =~ "^/radarr/" { }'."\n";
        $this->pmssWriteFile($fragment, $contents);

        $this->assertStringContainsString('[dry-run]', $this->pmssRunRouteStrip($fragment, 1));
        $this->assertSame($contents, file_get_contents($fragment));
        $this->assertSame([], glob($fragment.'.pmss.*'));
    }

    public function testStripSkipsSymlinksMissingFilesAndNonMatchingContent(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-media-routes-');
        $contents = '$HTTP["url"] =~ "^/sonarr/" { }';
        $this->pmssWriteFile($dir.'/target', $contents);
        symlink($dir.'/target', $dir.'/linked.conf');
        $this->pmssWriteFile($dir.'/custom.conf', '# no final newline');
        chmod($dir.'/custom.conf', 0600);
        foreach (['linked.conf', 'missing.conf', 'custom.conf'] as $name) {
            $this->pmssRunRouteStrip($dir.'/'.$name);
        }
        $this->assertTrue(is_link($dir.'/linked.conf'));
        $this->assertSame($contents, file_get_contents($dir.'/target'));
        $this->assertFalse(file_exists($dir.'/missing.conf'));
        $this->assertSame('# no final newline', file_get_contents($dir.'/custom.conf'));
        $this->assertSame(0600, fileperms($dir.'/custom.conf') & 0777);
    }

    private function pmssRunRouteStrip(string $fragment, int $dryRun = 0, bool $expectFailure = false): string
    {
        $call = 'lighttpd_custom_strip_managed_media_stack_routes '.escapeshellarg($fragment).' 1';
        return $this->pmssRunShellHarness(implode("\n", [
            '#!/usr/bin/env bash', 'set -euo pipefail', 'DRY_RUN='.$dryRun,
            'log_info() { echo "INFO:$*"; }', 'log_warn() { echo "WARN:$*"; }',
            $this->pmssExtractShellFunctions($this->script, ['lighttpd_custom_strip_managed_media_stack_routes']),
            $expectFailure ? 'if '.$call.'; then exit 1; fi' : $call, '',
        ]));
    }

    private function pmssRunPrepareLighttpd(?string $customContents, ?string $migratedContents = null): string
    {
        $home = $this->pmssMakeTempDir('pmss-media-stack-lighttpd-home-');
        $lighttpdDir = $home.'/.lighttpd';
        $customDir = $lighttpdDir.'/custom.d';

        $this->pmssEnsureDir($customDir);

        if ($customContents !== null) {
            $this->pmssWriteFile($lighttpdDir.'/custom', $customContents);
        }

        if ($migratedContents !== null) {
            $this->pmssWriteFile($customDir.'/custom-migrated.conf', $migratedContents);
        }

        $functions = $this->pmssExtractShellFunctions($this->script, [
            'lighttpd_custom_has_legacy_media_stack_rules',
            'lighttpd_custom_strip_managed_media_stack_routes',
            'prepare_lighttpd_media_stack_paths',
        ]);

        $script = implode("\n", [
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'HOME='.escapeshellarg($home),
            'DRY_RUN=0',
            'log_info() { :; }',
            'log_warn() { :; }',
            $functions,
            'prepare_lighttpd_media_stack_paths',
            '',
        ]);

        $this->pmssRunShellHarness($script, 'pmss-media-stack-lighttpd-harness-');

        return $home;
    }
}
