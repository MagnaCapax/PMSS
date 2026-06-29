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
        $this->script = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
    }

    public function testServarrBranchDefaultsAndOverridesPresent(): void
    {
        foreach (array(
            'SONARR_BRANCH="${OVR_SONARR_BRANCH:-main}"' => 'Sonarr default branch should be main',
            'RADARR_BRANCH="${OVR_RADARR_BRANCH:-master}"' => 'Radarr default branch should be master',
            'PROWLARR_BRANCH="${OVR_PROWLARR_BRANCH:-master}"' => 'Prowlarr default branch should be master',
            'SONARR_MAJOR="${OVR_SONARR_VERSION:-4}"' => 'Sonarr version default should be 4',
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
        $this->assertStringContainsAllStrings([
            'runtime=netcore&arch=${SERVARR_ARCH}',
            'SERVARR_DOWNLOAD_URL="${PROWLARR_UPDATE_BASE}/${PROWLARR_BRANCH}/updatefile?os=linux&runtime=netcore&arch=${SERVARR_ARCH}"',
        ], $this->script);
    }

    public function testInstallServarrAppCreatesLoopbackPublicConfig(): void
    {
        $this->assertStringContainsAndOmitsStrings([
            '<Config>',
            '/public-${USERNAME}/${app}</UrlBase>',
            '<BindAddress>127.0.0.1</BindAddress>',
        ], [
            '<BindAddress>*</BindAddress>' => 'Servarr defaults must not bind wildcard address',
        ], $this->script);
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

    public function testAspDotnetRuntimeUsesSharedArchiveExtractor(): void
    {
        $oldTarCommand = 'tar -xvzf '.'"aspnetcore.tar.gz"';
        $oldRemoveCommand = 'rm -f '.'"aspnetcore.tar.gz"';

        $this->assertStringContainsString('extract_tgz "aspnetcore.tar.gz" "$installdir"', $this->script);
        $this->assertStringNotContainsString($oldTarCommand, $this->script);
        $this->assertStringNotContainsString($oldRemoveCommand, $this->script);
    }

    public function testSabnzbdAllowsProxiedWizardAccess(): void
    {
        $this->assertStringContainsAllStrings([
            'inet_exposure = 4',
            'sabnzbd_misc_value_set() {',
            'sabnzbd_misc_value_set "$datadir/${app}.ini" inet_exposure "4"',
        ], $this->script);
    }

    public function testManagedAppShellPathMarkersPresent(): void
    {
        $this->assertStringContainsAllStrings([
            '$HOME/.bin/cloudplow',
            '$HOME/.config/sabnzbd',
            'export DOTNET_ROOT=$HOME/.bin/dotnet',
            'PATH="$PATH:$DOTNET_ROOT"',
            'PATH="$PATH:$HOME/.bin"',
            '.bashrc.custom',
        ], $this->script);
    }

    public function testJellyfinRemoteAccessDisabled(): void
    {
        $this->assertStringContainsString('<EnableRemoteAccess>false</EnableRemoteAccess>', $this->script);
    }

    public function testJellyfinLocalNetworkAddressSet(): void
    {
        $this->assertStringContainsString('<EnableRemoteAccess>false</EnableRemoteAccess>', $this->script);
        $this->assertOrderedStrings(array(
            '<LocalNetworkSubnets />',
            '<LocalNetworkAddresses>',
            '<string>127.0.0.1</string>',
            '</LocalNetworkAddresses>',
        ), $this->script);
        $removedHelper = 'ensure_jellyfin_local'.'_bind';
        $this->assertStringNotContainsString($removedHelper, $this->script);
    }

    public function testJellyfinAspNetCoreUrlsUsed(): void
    {
        $this->assertStringContainsAllStrings([
            'ASPNETCORE_URLS=',
            '127.0.0.1:',
        ], $this->script);
    }

    public function testJellyfinFfmpegFallbackUsesExistingOverridePath(): void
    {
        $this->assertStringContainsAllStrings([
            'JELLYFIN_MIN_FFMPEG_VERSION="4.4"',
            'jellyfin_ffmpeg_configure_fallback',
            'OVR_JELLYFIN_FFMPEG="$home_ffmpeg"',
            'dpkg --compare-versions "$version" ge "$JELLYFIN_MIN_FFMPEG_VERSION"',
        ], $this->script);
    }

    public function testJellyfinFfmpegFallbackSkipsWithoutImplicitDownload(): void
    {
        $staticFfmpegPrefix = 'JELLYFIN_STATIC_'.'FFMPEG';
        $this->assertStringContainsAndOmitsStrings([
            'JELLYFIN_INSTALL_ENABLED=0',
            'Skipping Jellyfin: FFmpeg ${JELLYFIN_MIN_FFMPEG_VERSION}+ is required',
            'bash install-media-stack.sh --jellyfin-ffmpeg=$home_ffmpeg',
        ], [
            $staticFfmpegPrefix => 'Installer must not auto-download third-party static FFmpeg builds',
        ], $this->script);
    }

    public function testJellyfinSystemXmlTagSetterLocksSnapshot(): void
    {
        $script = implode("\n", array(
            '#!/usr/bin/env bash', 'set -euo pipefail', 'file=$(mktemp)',
            $this->pmssExtractShellFunctions($this->script, array('xml_escape', 'sed_replacement_escape', 'jellyfin_system_xml_tag_set')),
            'printf "%s\n" "<ServerConfiguration>" "  <BaseUrl>old</BaseUrl>" "</ServerConfiguration>" > "$file"',
            'jellyfin_system_xml_tag_set "$file" BaseUrl /public-alice/jellyfin; jellyfin_system_xml_tag_set "$file" FFmpegPath "/opt/a&b/ffmpeg"; cat "$file"', '',
        ));
        $this->assertSame("<ServerConfiguration>\n  <BaseUrl>/public-alice/jellyfin</BaseUrl>\n  <FFmpegPath>/opt/a&amp;b/ffmpeg</FFmpegPath>\n</ServerConfiguration>", $this->pmssRunShellHarness($script));
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

    public function testLighttpdMediaStackMigrationMarkersPresent(): void
    {
        $this->assertStringContainsAllStrings([
            '/.lighttpd/custom.d/media-stack.conf',
            'prepare_lighttpd_media_stack_paths',
            'custom-migrated.conf',
            'lighttpd_custom_has_legacy_media_stack_rules',
            'lighttpd_custom_strip_managed_media_stack_routes',
            'Removed PMSS-managed media stack proxy routes',
        ], $this->script);
    }

    public function testLighttpdManagedRouteStripPreservesUserRulesSnapshot(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-stack-lighttpd-strip-home-');
        $fragment = $home.'/custom-migrated.conf';
        $functions = $this->pmssExtractShellFunctions($this->script, array('lighttpd_custom_strip_managed_media_stack_routes'));
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
        $removedHelper = 'managed_install_path_reset_target'.'_is_safe';
        $this->assertStringContainsAndOmitsStrings([
            'Keeping existing ~/.bin contents outside PMSS-managed app paths.',
            'managed_install_path_reset',
        ], [
            $removedHelper,
            'rm -rf "$HOME/.bin"' => 'Installer must not delete the entire ~/.bin directory on reruns',
        ], $this->script);
    }

    public function testManagedInstallPathResetRefusesUnsafeTargets(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-stack-reset-home-');
        $this->pmssWriteFile($home.'/.bin/Radarr/file', 'managed');
        $this->pmssWriteFile($home.'/.bin/keep/file', 'preserve');
        $this->pmssEnsureDir($home.'/.config/sabnzbd');
        $this->pmssEnsureDir($home.'/.config/unmanaged');

        $functions = $this->pmssExtractShellFunctions($this->script, array(
            'media_stack_home_path_is_safe',
            'media_stack_managed_paths',
            'media_stack_managed_path_allowed',
            'media_stack_managed_path_remove',
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
            'media_stack_managed_path_allowed "$HOME/.config/sabnzbd" && echo "managed_allowed"',
            'if media_stack_managed_path_allowed "$HOME/.config/unmanaged"; then echo "unmanaged_allowed"; elif [[ -d "$HOME/.config/unmanaged" ]]; then echo "unmanaged_preserved"; fi',
            'media_stack_managed_path_remove "$HOME/.config/sabnzbd" && [[ ! -e "$HOME/.config/sabnzbd" ]] && echo "managed_removed"',
            'if [[ -f "$HOME/.bin/keep/file" ]]; then echo "keep_preserved"; fi',
            '',
        ));

        $output = $this->pmssRunShellHarness($script);

        $this->assertStringContainsAndOmitsStrings([
            'safe_removed',
            'unsafe_refused',
            'traversal_refused',
            'managed_allowed',
            'managed_removed',
            'unmanaged_preserved',
            'keep_preserved',
        ], [
            'safe_still_exists' => $output,
            'unsafe_allowed' => $output,
            'traversal_allowed' => $output,
            'unmanaged_allowed' => $output,
        ], $output);
    }

    public function testJellyfinConfigResetUsesExactPathGuard(): void
    {
        $removedHelper = 'jellyfin_config_dir_reset_target'.'_is_safe';
        $this->assertStringNotContainsString($removedHelper, $this->script);
        $this->assertStringContainsString('if ! media_stack_home_path_is_safe || [[ "$path" != "$HOME/.config/jellyfin" ]]; then', $this->script);
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
        $this->pmssEnsureDir($home.'/.lighttpd/custom.d');

        $functions = $this->pmssExtractShellFunctions($this->script, array(
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
            '"upgrade" => "enable"',
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
        $this->assertSame(5, substr_count($withJellyfin, '"upgrade" => "enable"'));
        $this->assertSame(4, substr_count($withoutJellyfin, '"upgrade" => "enable"'));
        $this->assertStringNotContainsString('jellyfin', $withoutJellyfin);
    }

    public function testLighttpdMediaStackFragmentWriteIsAtomic(): void
    {
        $this->assertStringContainsString('local temp_target="${target}.pmss.$$"', $this->script);
        $this->assertStringContainsString('} >"$temp_target" || {', $this->script);
        $this->assertStringContainsString('if ! mv "$temp_target" "$target"; then', $this->script);
    }

    public function testHomePathGuardRunsBeforeLogFileCreation(): void
    {
        $this->assertStringContainsString('media_stack_home_path_is_safe() {', $this->script);
        $guard = strpos($this->script, 'if ! media_stack_home_path_is_safe; then');
        $logFile = strpos($this->script, 'LOG_FILE="$HOME/.install-media-stack.log"');

        $this->assertTrue($guard !== false, 'HOME path guard missing');
        $this->assertTrue($logFile !== false, 'Log file setup missing');
        $this->assertTrue($guard < $logFile, 'HOME must be validated before touching the log file');
    }

    public function testHomePathGuardRejectsUnsafeValues(): void
    {
        $functions = $this->pmssExtractShellFunctions($this->script, array('media_stack_home_path_is_safe'));
        $script = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            $functions,
            'HOME=/home/alice; if media_stack_home_path_is_safe; then echo safe_home; fi',
            'HOME=/; if media_stack_home_path_is_safe; then echo root_allowed; else echo root_refused; fi',
            'unset HOME; if media_stack_home_path_is_safe; then echo unset_allowed; else echo unset_refused; fi',
            'HOME=relative; if media_stack_home_path_is_safe; then echo relative_allowed; else echo relative_refused; fi',
            '',
        ));

        $output = $this->pmssRunShellHarness($script);

        $this->assertStringContainsAndOmitsStrings(
            ['safe_home', 'root_refused', 'unset_refused', 'relative_refused'],
            ['root_allowed' => $output, 'unset_allowed' => $output, 'relative_allowed' => $output],
            $output
        );
    }

    public function testDryRunLoggingPresent(): void
    {
        $this->assertStringContainsString('[dry-run]', $this->script);
    }

    public function testVerifyOnlyModePresent(): void
    {
        $this->assertStringContainsString('--verify-only', $this->script);
    }

    public function testDryRunUrlChecksUseHttpProbeHelpers(): void
    {
        $this->assertStringContainsAllStrings([
            'if [[ $DRY_RUN -eq 1 ]]; then',
            'check_url "$url"',
            'curl -fsIL --max-time 10 "$url"',
            'wget -q --spider --timeout=10 "$url"',
        ], $this->script);
    }

    public function testMetadataFetchUsesCurlOrWgetHelper(): void
    {
        $this->assertStringContainsAllStrings([
            'fetch_text() {',
            'curl -fsSL --max-time 20 "$url"',
            'wget -q -O - --timeout=20 --tries=1 "$url"',
            'SABNZBD_RELEASE_JSON=$(fetch_text "https://api.github.com/repos/sabnzbd/sabnzbd/releases/latest")',
            'JF_REPO_INDEX=$(fetch_text "$JF_REPO_BASE")',
            'Could not resolve SABnzbd release metadata from GitHub',
        ], $this->script);
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
        $this->assertStringContainsAllStrings([
            'MEDIA_STACK_STOP_SESSIONS=(sabnzbd radarr prowlarr sonarr cloudplow)',
            'media_stack_sessions "${MEDIA_STACK_STOP_SESSIONS[@]}"',
            'while IFS= read -r app; do',
            'tmux kill-session -t "${app}"',
        ], $this->script);
    }

    public function testSourceBashrcIsFailSoft(): void
    {
        $this->assertStringContainsString('source "$HOME/.bashrc" || true', $this->script);
    }

    public function testExistingPortSelectionRejectsInvalidValues(): void
    {
        $functions = $this->pmssExtractShellFunctions($this->script, array(
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

        $this->assertStringContainsAllStrings([
            'valid=8080',
            'zero=23456',
            'text=23456',
            'high=23456',
            "WARN:Ignoring invalid existing port '0'",
            "WARN:Ignoring invalid existing port 'abc'",
            "WARN:Ignoring invalid existing port '70000'",
        ], $output);
    }

    public function testJellyfinSedDoesNotUseSlashDelimitersWithClosingTags(): void
    {
        $this->assertStringContainsAndOmitsStrings([
            's|(<PublicHttpPort>)[^<]*(</PublicHttpPort>)|',
            's|(<InternalHttpPort>)[^<]*(</InternalHttpPort>)|',
        ], [
            's/\\(<PublicHttpPort>\\)' => 'Must not use / delimiters that break on </tag>',
        ], $this->script);
    }

    public function testServarrDownloadResolversPreserveUrlContracts(): void
    {
        $this->assertStringContainsAndOmitsStrings([
            'for servarr_spec in \\',
            '"radarr|Radarr|$HOME/.config/radarr|$RADARR_PORT|7878|echo"',
            '"prowlarr|Prowlarr|$HOME/.config/prowlarr|$PROWLARR_PORT|9696|log_step"',
            '"sonarr|Sonarr|$HOME/.config/sonarr|$SONARR_PORT|8989|log_step"',
        ], [
            'servarr_resolve'.'_radarr_download_url',
            'servarr_resolve'.'_prowlarr_download_url',
            'servarr_resolve'.'_sonarr_download_url',
        ], $this->script);

        $functions = $this->pmssExtractShellFunctions($this->script, array(
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

        $this->assertStringContainsAllStrings([
            'radarr_override=https://mirror.example/radarr.tar.gz',
            'radarr_pin=https://github.com/Radarr/Radarr/releases/download/v5.10.4.9218/Radarr.master.5.10.4.9218.linux-core-x64.tar.gz',
            'radarr_api=https://radarr.servarr.com/v1/update/master/updatefile?os=linux&runtime=netcore&arch=x64',
            'prowlarr_override=https://mirror.example/prowlarr.tar.gz',
            'prowlarr_api=https://prowlarr.servarr.com/v1/update/master/updatefile?os=linux&runtime=netcore&arch=x64',
            'sonarr_override=https://mirror.example/sonarr.tar.gz',
            'sonarr_api=https://services.sonarr.tv/v1/download/main/latest?version=4&os=linux&arch=x64',
        ], $output);
    }

    public function testServarrRadarrResolverPreservesOldGlibcPin(): void
    {
        $functions = $this->pmssExtractShellFunctions($this->script, array(
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

        $this->assertStringContainsAllStrings([
            'WARN:Detected GLIBC 2.31 < 2.33',
            'url=https://github.com/Radarr/Radarr/releases/download/v5.10.4.9218/Radarr.master.5.10.4.9218.linux-core-x64.tar.gz',
        ], $output);
    }

    public function testServarrInstallHelperRunsSharedDownloadAndConfigSequence(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-stack-servarr-home-');
        $trace = $home.'/trace.log';
        $this->pmssEnsureDir($home.'/.bin');

        $functions = $this->pmssExtractShellFunctions($this->script, array('fetch_verified_archive', 'servarr_install_from_url'));
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

}
