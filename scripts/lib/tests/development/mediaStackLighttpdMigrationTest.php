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
        $path = dirname(__DIR__, 4).'/etc/skel/install-media-stack.sh';
        $this->script = (string) file_get_contents($path);
        $this->assertTrue($this->script !== '', 'Failed to read install-media-stack.sh');
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

        $this->assertStringNotContainsString('^/sabnzbd(\$|/)', $migrated);
        $this->assertStringNotContainsString('^/radarr(\$|/)', $migrated);
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
            '',
        ]);

        $home = $this->pmssRunPrepareLighttpd($custom);
        $migrated = (string) file_get_contents($home.'/.lighttpd/custom.d/custom-migrated.conf');

        $this->assertStringContainsString('# user-owned rules stay intact', $migrated);
        $this->assertStringContainsString('^/custom(\$|/)', $migrated);
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

    private function pmssRunPrepareLighttpd(?string $customContents, ?string $migratedContents = null): string
    {
        $home = $this->pmssMakeTempDir('pmss-media-stack-lighttpd-home-');
        $lighttpdDir = $home.'/.lighttpd';
        $customDir = $lighttpdDir.'/custom.d';

        mkdir($customDir, 0755, true);

        if ($customContents !== null) {
            file_put_contents($lighttpdDir.'/custom', $customContents);
        }

        if ($migratedContents !== null) {
            file_put_contents($customDir.'/custom-migrated.conf', $migratedContents);
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
