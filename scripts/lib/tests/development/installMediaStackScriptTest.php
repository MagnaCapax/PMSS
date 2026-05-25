<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Static lint-style checks for etc/skel/install-media-stack.sh to ensure
 * critical defaults, URLs, and command wiring stay consistent.
 */
class installMediaStackScriptTest extends TestCase
{
    /** @var string */
    private $script;

    public function setUp(): void
    {
        // Walk to repo root and read the installer script directly.
        $path = dirname(__DIR__, 4).'/etc/skel/install-media-stack.sh';
        $this->script = file_get_contents($path);
        $this->assertTrue($this->script !== false, 'Failed to read install-media-stack.sh');
    }

    public function testServarrBranchDefaultsAndOverridesPresent(): void
    {
        foreach (array(
            'SONARR_BRANCH="main"' => 'Sonarr default branch should be main',
            'RADARR_BRANCH="master"' => 'Radarr default branch should be master',
            'PROWLARR_BRANCH="master"' => 'Prowlarr default branch should be master',
            'if [[ -n "$OVR_SONARR_BRANCH" ]]' => 'Sonarr branch override missing',
            'if [[ -n "$OVR_RADARR_BRANCH" ]]' => 'Radarr branch override missing',
            'if [[ -n "$OVR_PROWLARR_BRANCH" ]]' => 'Prowlarr branch override missing',
            'if [[ -n "$OVR_SONARR_VERSION" ]]' => 'Sonarr version override missing',
        ) as $needle => $message) {
            $this->assertStringContainsString($needle, $this->script, $message);
        }
    }

    public function testRadarrGlibcPinPresent(): void
    {
        $this->assertStringContainsString('v5.10.4.9218', $this->script);
    }

    public function testProwlarrRuntimeNetcorePresent(): void
    {
        $this->assertStringContainsString('runtime=netcore&arch=${SERVARR_ARCH}', $this->script);
        $this->assertStringContainsString('SERVARR_DOWNLOAD_URL="${PROWLARR_UPDATE_BASE}/${PROWLARR_BRANCH}/updatefile?os=linux&runtime=netcore&arch=${SERVARR_ARCH}"', $this->script);
    }

    public function testInstallServarrAppCreatesLoopbackPublicConfig(): void
    {
        $this->assertStringContainsString('<Config>', $this->script);
        $this->assertTrue(
            strpos($this->script, '<BindAddress>*</BindAddress>') === false,
            'Servarr defaults must not bind wildcard address'
        );
        $this->assertStringContainsString('/public-${USERNAME}/${app}</UrlBase>', $this->script);
        $this->assertStringContainsString('<BindAddress>127.0.0.1</BindAddress>', $this->script);
    }

    public function testCloudplowUsesBinDir(): void
    {
        $this->assertStringContainsString('$HOME/.bin/cloudplow', $this->script);
    }

    public function testVenvPipBootstrapUsesPython3(): void
    {
        $this->assertStringContainsString('python_venv_install_requirements() {', $this->script);
        $this->assertTrue(
            substr_count($this->script, 'python_venv_install_requirements "$installdir"') === 2,
            'Cloudplow and SABnzbd should share the venv requirements helper'
        );
        $this->assertStringContainsString('python3 -m pip install -U pip >/dev/null 2>&1', $this->script);
        $this->assertTrue(
            strpos($this->script, 'python -m pip install -U pip >/dev/null 2>&1') === false,
            'Media stack venv bootstrap must not rely on bare python'
        );
    }

    public function testSabnzbdUsesConfigDir(): void
    {
        $this->assertStringContainsString('$HOME/.config/sabnzbd', $this->script);
    }

    public function testSabnzbdAllowsProxiedWizardAccess(): void
    {
        $this->assertStringContainsString('inet_exposure = 4', $this->script);
        $this->assertStringContainsString('s#^inet_exposure = .*#inet_exposure = 4#', $this->script);
        $this->assertStringContainsString('/^\[misc\]/a inet_exposure = 4', $this->script);
    }

    public function testDotnetRootExportedInBashrc(): void
    {
        $this->assertStringContainsString('export DOTNET_ROOT=$HOME/.bin/dotnet', $this->script);
    }

    public function testBinDirAppendedToPath(): void
    {
        $this->assertStringContainsString('PATH="$PATH:$DOTNET_ROOT"', $this->script);
        $this->assertStringContainsString('PATH="$PATH:$HOME/.bin"', $this->script);
    }

    public function testBashrcCustomUsedForAppends(): void
    {
        $this->assertStringContainsString('.bashrc.custom', $this->script);
    }

    public function testJellyfinRemoteAccessDisabled(): void
    {
        $this->assertStringContainsString('<EnableRemoteAccess>false</EnableRemoteAccess>', $this->script);
    }

    public function testJellyfinLocalNetworkAddressSet(): void
    {
        $this->assertStringContainsString('<string>127.0.0.1</string>', $this->script);
    }

    public function testJellyfinAspNetCoreUrlsUsed(): void
    {
        $this->assertStringContainsString('ASPNETCORE_URLS=', $this->script);
        $this->assertStringContainsString('127.0.0.1:', $this->script);
    }

    public function testJellyfinFfmpegFallbackUsesExistingOverridePath(): void
    {
        $this->assertStringContainsString('JELLYFIN_MIN_FFMPEG_VERSION="4.4"', $this->script);
        $this->assertStringContainsString('jellyfin_ffmpeg_configure_fallback', $this->script);
        $this->assertStringContainsString('OVR_JELLYFIN_FFMPEG="$home_ffmpeg"', $this->script);
        $this->assertStringContainsString('dpkg --compare-versions "$version" ge "$JELLYFIN_MIN_FFMPEG_VERSION"', $this->script);
    }

    public function testJellyfinFfmpegFallbackSkipsWithoutImplicitDownload(): void
    {
        $this->assertStringContainsString('JELLYFIN_INSTALL_ENABLED=0', $this->script);
        $this->assertStringContainsString('Skipping Jellyfin: FFmpeg ${JELLYFIN_MIN_FFMPEG_VERSION}+ is required', $this->script);
        $this->assertStringContainsString('bash install-media-stack.sh --jellyfin-ffmpeg=$home_ffmpeg', $this->script);
        $staticFfmpegPrefix = 'JELLYFIN_STATIC_'.'FFMPEG';
        $this->assertTrue(
            strpos($this->script, $staticFfmpegPrefix) === false,
            'Installer must not auto-download third-party static FFmpeg builds'
        );
    }

    public function testJellyfinFfmpegPreflightRunsBeforeDataLossPrompt(): void
    {
        $preflight = strpos($this->script, "esac\n\njellyfin_ffmpeg_configure_fallback");
        $prompt = strpos($this->script, 'WARNING: %s exists and will be removed');

        $this->assertTrue($preflight !== false, 'Jellyfin FFmpeg pre-flight call missing');
        $this->assertTrue($prompt !== false, 'Jellyfin data-loss prompt missing');
        $this->assertTrue($preflight < $prompt, 'FFmpeg pre-flight must run before Jellyfin config deletion prompt');
        $this->assertStringContainsString('Leaving existing Jellyfin config untouched because this run will skip Jellyfin.', $this->script);
    }

    public function testLighttpdMediaStackFragmentPathExists(): void
    {
        $this->assertStringContainsString('/.lighttpd/custom.d/media-stack.conf', $this->script);
        $this->assertStringContainsString('prepare_lighttpd_media_stack_paths', $this->script);
    }

    public function testLegacyLighttpdCustomMigrationPreservesUserRules(): void
    {
        $this->assertStringContainsString('custom-migrated.conf', $this->script);
        $this->assertStringContainsString('lighttpd_custom_has_legacy_media_stack_rules', $this->script);
    }

    public function testLegacyLighttpdCustomMigrationPrunesManagedProxyRoutes(): void
    {
        $this->assertStringContainsString('lighttpd_custom_strip_managed_media_stack_routes', $this->script);
        $this->assertStringContainsString('Removed PMSS-managed media stack proxy routes', $this->script);
    }

    public function testLighttpdManagedRouteStripPreservesUserRulesSnapshot(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-stack-lighttpd-strip-home-');
        $fragment = $home.'/custom-migrated.conf';
        $functions = $this->pmssExtractShellFunctions(array('lighttpd_custom_strip_managed_media_stack_routes'));
        $script = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'FRAGMENT='.escapeshellarg($fragment),
            'log_info() { echo "INFO:$*"; }',
            'log_warn() { echo "WARN:$*"; }',
            'cat > "$FRAGMENT" <<\'LIGHTTPD\'',
            '# User-owned route before',
            '$HTTP["url"] =~ "^/custom(\$|/)" {',
            '  proxy.server = ( "" => ( ( "host" => "127.0.0.1", "port" => 12000 ) ) )',
            '}',
            '$HTTP["url"] =~ "^/sabnzbd(\$|/)" {',
            '  proxy.server = ( "" => ( ( "host" => "127.0.0.1", "port" => 18080 ) ) )',
            '}',
            '$HTTP["url"] =~ "^/radarr(\$|/)" {',
            '  proxy.server = ( "" => ( ( "host" => "127.0.0.1", "port" => 17878 ) ) )',
            '}',
            '# User-owned route after',
            '$HTTP["url"] =~ "^/custom-after(\$|/)" {',
            '  proxy.server = ( "" => ( ( "host" => "127.0.0.1", "port" => 12001 ) ) )',
            '}',
            'LIGHTTPD',
            $functions,
            'lighttpd_custom_strip_managed_media_stack_routes "$FRAGMENT"',
            '',
        ));

        $this->pmssRunShellHarness($script);

        $expected = <<<'LIGHTTPD'
# User-owned route before
$HTTP["url"] =~ "^/custom(\$|/)" {
  proxy.server = ( "" => ( ( "host" => "127.0.0.1", "port" => 12000 ) ) )
}
# User-owned route after
$HTTP["url"] =~ "^/custom-after(\$|/)" {
  proxy.server = ( "" => ( ( "host" => "127.0.0.1", "port" => 12001 ) ) )
}
LIGHTTPD;
        $this->assertSame($expected."\n", (string) file_get_contents($fragment));
    }

    public function testManagedBinPathsRefreshInPlace(): void
    {
        $this->assertStringContainsString('Keeping existing ~/.bin contents outside PMSS-managed app paths.', $this->script);
        $this->assertStringContainsString('managed_install_path_reset', $this->script);
        $this->assertStringContainsString('managed_install_path_reset_target_is_safe', $this->script);
        $this->assertTrue(
            strpos($this->script, 'rm -rf "$HOME/.bin"') === false,
            'Installer must not delete the entire ~/.bin directory on reruns'
        );
    }

    public function testManagedInstallPathResetRefusesUnsafeTargets(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-stack-reset-home-');
        mkdir($home.'/.bin/Radarr', 0755, true);
        mkdir($home.'/.bin/keep', 0755, true);
        file_put_contents($home.'/.bin/Radarr/file', 'managed');
        file_put_contents($home.'/.bin/keep/file', 'preserve');

        $functions = $this->pmssExtractShellFunctions(array(
            'managed_install_path_reset_target_is_safe',
            'managed_install_path_reset',
        ));
        $script = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'HOME='.escapeshellarg($home),
            'DRY_RUN=0',
            'log_info() { echo "INFO:$*"; }',
            'log_err() { echo "ERR:$*"; }',
            $functions,
            'if managed_install_path_reset "$HOME/.bin/Radarr"; then echo "safe_removed"; else echo "safe_failed"; fi',
            'if [[ -e "$HOME/.bin/Radarr" ]]; then echo "safe_still_exists"; fi',
            'if managed_install_path_reset "$HOME/.bin"; then echo "unsafe_allowed"; else echo "unsafe_refused"; fi',
            'if managed_install_path_reset "$HOME/.bin/../outside"; then echo "traversal_allowed"; else echo "traversal_refused"; fi',
            'if [[ -f "$HOME/.bin/keep/file" ]]; then echo "keep_preserved"; fi',
            '',
        ));

        $output = $this->pmssRunShellHarness($script);

        $this->assertStringContainsString('safe_removed', $output);
        $this->assertStringContainsString('unsafe_refused', $output);
        $this->assertStringContainsString('traversal_refused', $output);
        $this->assertStringContainsString('keep_preserved', $output);
        $this->assertTrue(strpos($output, 'safe_still_exists') === false, $output);
        $this->assertTrue(strpos($output, 'unsafe_allowed') === false, $output);
        $this->assertTrue(strpos($output, 'traversal_allowed') === false, $output);
    }

    public function testJellyfinConfigResetUsesExactPathGuard(): void
    {
        $this->assertStringContainsString('jellyfin_config_dir_reset_target_is_safe', $this->script);
        $this->assertStringContainsString('[[ "$path" == "$HOME/.config/jellyfin" ]]', $this->script);
        $this->assertTrue(
            strpos($this->script, 'rm -rf "$HOME/.config/jellyfin"') === false,
            'Jellyfin config reset must route through the exact-path guard'
        );
    }

    public function testJellyfinPromptExplainsDataLoss(): void
    {
        $this->assertStringContainsString('Jellyfin users, metadata, and watch state will be lost.', $this->script);
    }

    public function testLighttpdMediaStackFragmentRendererPreservesProxyContracts(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-stack-lighttpd-render-home-');
        mkdir($home.'/.lighttpd/custom.d', 0755, true);

        $functions = $this->pmssExtractShellFunctions(array(
            'lighttpd_media_stack_proxy_block_write',
            'lighttpd_media_stack_config_write',
        ));
        $script = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'HOME='.escapeshellarg($home),
            'USERNAME=alice',
            'SABNZBD_PORT=18080 RADARR_PORT=17878 PROWLARR_PORT=19696 SONARR_PORT=18989 JELLYFIN_PORT=18096',
            'JELLYFIN_INSTALL_ENABLED=1',
            $functions,
            'lighttpd_media_stack_config_write "$HOME/.lighttpd/custom.d/media-stack.conf"',
            'JELLYFIN_INSTALL_ENABLED=0',
            'lighttpd_media_stack_config_write "$HOME/.lighttpd/custom.d/media-stack-no-jellyfin.conf"',
            '',
        ));

        $this->pmssRunShellHarness($script);
        $withJellyfin = (string) file_get_contents($home.'/.lighttpd/custom.d/media-stack.conf');
        $withoutJellyfin = (string) file_get_contents($home.'/.lighttpd/custom.d/media-stack-no-jellyfin.conf');

        $this->assertOrderedStrings(array(
            'Location and Set-Cookie Path rewriting belongs here via',
            'map-urlpath so nginx stays a minimal per-user front door.',
            '"^/radarr$" => "/public-alice/radarr/"',
            '"^/sonarr$" => "/public-alice/sonarr/"',
            '"^/prowlarr$" => "/public-alice/prowlarr/"',
            '$HTTP["url"] =~ "^/sabnzbd(\$|/)" {',
            '"port" => 18080',
            '"/sabnzbd" => "/public-alice/sabnzbd"',
            '$HTTP["url"] =~ "^/radarr(\$|/)" {',
            '"port" => 17878',
            '"/radarr" => "/public-alice/radarr"',
            '$HTTP["url"] =~ "^/prowlarr(\$|/)" {',
            '"port" => 19696',
            '"/prowlarr" => "/public-alice/prowlarr"',
            '$HTTP["url"] =~ "^/sonarr(\$|/)" {',
            '"port" => 18989',
            '"/sonarr" => "/public-alice/sonarr"',
            '$HTTP["url"] =~ "^/jellyfin(\$|/)" {',
            '"port" => 18096',
            '"/jellyfin" => "/public-alice/jellyfin"',
        ), $withJellyfin);
        $this->assertStringNotContainsString('jellyfin', $withoutJellyfin);
    }

    public function testDryRunLoggingPresent(): void
    {
        $this->assertStringContainsString('[dry-run]', $this->script);
    }

    public function testVerifyOnlyModePresent(): void
    {
        $this->assertStringContainsString('--verify-only', $this->script);
    }

    public function testFetchUsesCheckUrlOnDryRun(): void
    {
        $this->assertStringContainsString('if [[ $DRY_RUN -eq 1 ]]; then', $this->script);
        $this->assertStringContainsString('check_url "$url"', $this->script);
    }

    public function testCheckUrlUsesCurlOrWget(): void
    {
        $this->assertStringContainsString('curl -fsIL --max-time 10 "$url"', $this->script);
        $this->assertStringContainsString('wget -q --spider --timeout=10 "$url"', $this->script);
    }

    public function testLogFilePathSetOnce(): void
    {
        $this->assertStringContainsString('LOG_FILE="$HOME/.install-media-stack.log"', $this->script);
    }

    public function testTmuxDoesNotUseGlobalPkill(): void
    {
        $this->assertTrue(strpos($this->script, 'pkill -9 -f -u "$USERNAME" tmux') === false, 'Must not pkill all tmux sessions for the user');
    }

    public function testTmuxKillIsScopedToNamedSessions(): void
    {
        $this->assertStringContainsString('MEDIA_STACK_STOP_SESSIONS=(sabnzbd radarr prowlarr sonarr cloudplow)', $this->script);
        $this->assertStringContainsString('media_stack_sessions "${MEDIA_STACK_STOP_SESSIONS[@]}"', $this->script);
        $this->assertStringContainsString('while IFS= read -r app; do', $this->script);
        $this->assertStringContainsString('tmux kill-session -t "${app}"', $this->script);
    }

    public function testSourceBashrcIsFailSoft(): void
    {
        $this->assertStringContainsString('source "$HOME/.bashrc" || true', $this->script);
    }

    public function testExistingPortSelectionRejectsInvalidValues(): void
    {
        $functions = $this->pmssExtractShellFunctions(array(
            'media_stack_port_is_valid',
            'pick_existing_or_random_port',
        ));
        $script = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'log_warn() { echo "WARN:$*" >&2; }',
            'random_open_port() { echo 23456; }',
            $functions,
            'echo "valid=$(pick_existing_or_random_port 8080)"',
            'echo "zero=$(pick_existing_or_random_port 0)"',
            'echo "text=$(pick_existing_or_random_port abc)"',
            'echo "high=$(pick_existing_or_random_port 70000)"',
            '',
        ));

        $output = $this->pmssRunShellHarness($script);

        $this->assertStringContainsString('valid=8080', $output);
        $this->assertStringContainsString('zero=23456', $output);
        $this->assertStringContainsString('text=23456', $output);
        $this->assertStringContainsString('high=23456', $output);
        $this->assertStringContainsString("WARN:Ignoring invalid existing port '0'", $output);
        $this->assertStringContainsString("WARN:Ignoring invalid existing port 'abc'", $output);
        $this->assertStringContainsString("WARN:Ignoring invalid existing port '70000'", $output);
    }

    public function testJellyfinSedDoesNotUseSlashDelimitersWithClosingTags(): void
    {
        $this->assertTrue(strpos($this->script, 's/\\(<PublicHttpPort>\\)') === false, 'Must not use / delimiters that break on </tag>');
        $this->assertStringContainsString('s|(<PublicHttpPort>)[^<]*(</PublicHttpPort>)|', $this->script);
        $this->assertStringContainsString('s|(<InternalHttpPort>)[^<]*(</InternalHttpPort>)|', $this->script);
    }

    public function testServarrDownloadResolversPreserveUrlContracts(): void
    {
        $this->assertStringContainsString('for servarr_spec in \\', $this->script);
        $this->assertStringContainsString('"radarr|Radarr|$HOME/.config/radarr|$RADARR_PORT|7878|echo"', $this->script);
        $this->assertStringContainsString('"prowlarr|Prowlarr|$HOME/.config/prowlarr|$PROWLARR_PORT|9696|log_step"', $this->script);
        $this->assertStringContainsString('"sonarr|Sonarr|$HOME/.config/sonarr|$SONARR_PORT|8989|log_step"', $this->script);
        $this->pmssAssertStringNotContainsString('servarr_resolve'.'_radarr_download_url', $this->script);
        $this->pmssAssertStringNotContainsString('servarr_resolve'.'_prowlarr_download_url', $this->script);
        $this->pmssAssertStringNotContainsString('servarr_resolve'.'_sonarr_download_url', $this->script);

        $functions = $this->pmssExtractShellFunctions(array(
            'servarr_resolve_download_url',
        ));
        $script = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'SERVARR_ARCH=x64',
            'RADARR_BRANCH=master',
            'PROWLARR_BRANCH=master',
            'SONARR_BRANCH=main',
            'SONARR_MAJOR=4',
            'RADARR_UPDATE_BASE=https://radarr.servarr.com/v1/update',
            'PROWLARR_UPDATE_BASE=https://prowlarr.servarr.com/v1/update',
            'SONARR_DL_BASE=https://services.sonarr.tv/v1/download',
            'OVR_RADARR_URL=https://mirror.example/radarr.tar.gz',
            'OVR_RADARR_VERSION=',
            'OVR_PROWLARR_URL=https://mirror.example/prowlarr.tar.gz',
            'OVR_SONARR_URL=',
            'SERVARR_DOWNLOAD_URL=',
            'log_warn() { echo "WARN:$*" >&2; }',
            'getconf() { echo "glibc 2.36"; }',
            'dpkg() { return 0; }',
            $functions,
            'servarr_resolve_download_url "radarr"',
            'echo "radarr_override=$SERVARR_DOWNLOAD_URL"',
            'OVR_RADARR_URL=',
            'OVR_RADARR_VERSION=v5.10.4.9218',
            'servarr_resolve_download_url "radarr"',
            'echo "radarr_pin=$SERVARR_DOWNLOAD_URL"',
            'OVR_RADARR_VERSION=',
            'servarr_resolve_download_url "radarr"',
            'echo "radarr_api=$SERVARR_DOWNLOAD_URL"',
            'servarr_resolve_download_url "prowlarr"',
            'echo "prowlarr_override=$SERVARR_DOWNLOAD_URL"',
            'OVR_PROWLARR_URL=',
            'servarr_resolve_download_url "prowlarr"',
            'echo "prowlarr_api=$SERVARR_DOWNLOAD_URL"',
            'OVR_SONARR_URL=https://mirror.example/sonarr.tar.gz',
            'servarr_resolve_download_url "sonarr"',
            'echo "sonarr_override=$SERVARR_DOWNLOAD_URL"',
            'OVR_SONARR_URL=',
            'servarr_resolve_download_url "sonarr"',
            'echo "sonarr_api=$SERVARR_DOWNLOAD_URL"',
            '',
        ));

        $output = $this->pmssRunShellHarness($script);

        $this->assertStringContainsString('radarr_override=https://mirror.example/radarr.tar.gz', $output);
        $this->assertStringContainsString('radarr_pin=https://github.com/Radarr/Radarr/releases/download/v5.10.4.9218/Radarr.master.5.10.4.9218.linux-core-x64.tar.gz', $output);
        $this->assertStringContainsString('radarr_api=https://radarr.servarr.com/v1/update/master/updatefile?os=linux&runtime=netcore&arch=x64', $output);
        $this->assertStringContainsString('prowlarr_override=https://mirror.example/prowlarr.tar.gz', $output);
        $this->assertStringContainsString('prowlarr_api=https://prowlarr.servarr.com/v1/update/master/updatefile?os=linux&runtime=netcore&arch=x64', $output);
        $this->assertStringContainsString('sonarr_override=https://mirror.example/sonarr.tar.gz', $output);
        $this->assertStringContainsString('sonarr_api=https://services.sonarr.tv/v1/download/main/latest?version=4&os=linux&arch=x64', $output);
    }

    public function testServarrRadarrResolverPreservesOldGlibcPin(): void
    {
        $functions = $this->pmssExtractShellFunctions(array(
            'servarr_resolve_download_url',
        ));
        $script = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'SERVARR_ARCH=x64',
            'RADARR_BRANCH=master',
            'RADARR_UPDATE_BASE=https://radarr.servarr.com/v1/update',
            'OVR_RADARR_URL=',
            'OVR_RADARR_VERSION=',
            'SERVARR_DOWNLOAD_URL=',
            'log_warn() { echo "WARN:$*"; }',
            'getconf() { echo "glibc 2.31"; }',
            'dpkg() { return 1; }',
            $functions,
            'servarr_resolve_download_url "radarr"',
            'echo "url=$SERVARR_DOWNLOAD_URL"',
            '',
        ));

        $output = $this->pmssRunShellHarness($script);

        $this->assertStringContainsString('WARN:Detected GLIBC 2.31 < 2.33', $output);
        $this->assertStringContainsString('url=https://github.com/Radarr/Radarr/releases/download/v5.10.4.9218/Radarr.master.5.10.4.9218.linux-core-x64.tar.gz', $output);
    }

    public function testServarrInstallHelperRunsSharedDownloadAndConfigSequence(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-stack-servarr-home-');
        $trace = $home.'/trace.log';
        mkdir($home.'/.bin', 0755, true);

        $functions = $this->pmssExtractShellFunctions(array('fetch_verified_archive', 'servarr_install_from_url'));
        $script = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'HOME='.escapeshellarg($home),
            'TRACE='.escapeshellarg($trace),
            'USERNAME=alice',
            'DRY_RUN=0',
            'log_info() { echo "info:$*" >> "$TRACE"; }',
            'log_err() { echo "err:$*" >> "$TRACE"; }',
            'managed_install_path_reset() { echo "reset:$1" >> "$TRACE"; }',
            'pkill() { echo "pkill:$*" >> "$TRACE"; }',
            'fetch() { echo "fetch:$1|$2" >> "$TRACE"; return 0; }',
            'verify_checksum() { echo "verify:$1|$2" >> "$TRACE"; return 0; }',
            'extract_tgz() { echo "extract:$*" >> "$TRACE"; }',
            'servarr_config_xml_converge() { echo "config:$1|$2|$3|$4" >> "$TRACE"; }',
            $functions,
            'servarr_install_from_url "radarr" "Radarr" "$HOME/.config/radarr" "17878" "7878" "https://example.invalid/updatefile?os=linux"',
            '',
        ));

        $this->pmssRunShellHarness($script);
        $traceOutput = (string) file_get_contents($trace);

        $this->assertOrderedStrings(array(
            'reset:'.$home.'/.bin/Radarr',
            'pkill:-9 -f -u alice Radarr',
            'info:Radarr URL: https://example.invalid/updatefile?os=linux',
            'fetch:https://example.invalid/updatefile?os=linux|Radarr.tar.gz',
            'verify:Radarr.tar.gz|updatefile',
            'extract:Radarr.tar.gz',
            'config:radarr|'.$home.'/.config/radarr|17878|7878',
        ), $traceOutput);
    }

    /**
     * @param array<int, string> $names
     */
    private function pmssExtractShellFunctions(array $names): string
    {
        $functions = array();
        foreach ($names as $name) {
            $pattern = '/^'.preg_quote($name, '/').'\(\) \{\n(?:.*\n)*?^\}/m';
            $matched = preg_match($pattern, $this->script, $matches);

            $this->assertSame(1, $matched, 'Failed to extract shell function '.$name);
            $functions[] = $matches[0];
        }

        return implode("\n\n", $functions);
    }

    private function pmssRunShellHarness(string $script): string
    {
        $harness = $this->pmssMakeTempDir('pmss-media-stack-harness-').'/run.sh';
        file_put_contents($harness, $script);
        chmod($harness, 0755);

        $output = array();
        exec(escapeshellarg($harness).' 2>&1', $output, $rc);
        $combined = implode("\n", $output);

        $this->assertSame(0, $rc, $combined);
        return $combined;
    }
}
