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

    public function testBranchDefaultSonarrMain(): void
    {
        $this->assertTrue(strpos($this->script, 'SONARR_BRANCH="main"') !== false, 'Sonarr default branch should be main');
    }

    public function testBranchDefaultRadarrMaster(): void
    {
        $this->assertTrue(strpos($this->script, 'RADARR_BRANCH="master"') !== false, 'Radarr default branch should be master');
    }

    public function testBranchDefaultProwlarrMaster(): void
    {
        $this->assertTrue(strpos($this->script, 'PROWLARR_BRANCH="master"') !== false, 'Prowlarr default branch should be master');
    }

    public function testBranchOverrideSonarr(): void
    {
        $this->assertTrue(strpos($this->script, 'if [[ -n "$OVR_SONARR_BRANCH" ]]') !== false, 'Sonarr branch override missing');
    }

    public function testBranchOverrideRadarr(): void
    {
        $this->assertTrue(strpos($this->script, 'if [[ -n "$OVR_RADARR_BRANCH" ]]') !== false, 'Radarr branch override missing');
    }

    public function testBranchOverrideProwlarr(): void
    {
        $this->assertTrue(strpos($this->script, 'if [[ -n "$OVR_PROWLARR_BRANCH" ]]') !== false, 'Prowlarr branch override missing');
    }

    public function testSonarrVersionOverride(): void
    {
        $this->assertTrue(strpos($this->script, 'if [[ -n "$OVR_SONARR_VERSION" ]]') !== false, 'Sonarr version override missing');
    }

    public function testRadarrGlibcPinPresent(): void
    {
        $this->assertStringContainsString('v5.10.4.9218', $this->script);
    }

    public function testProwlarrRuntimeNetcorePresent(): void
    {
        $this->assertStringContainsString('runtime=netcore&arch=${SERVARR_ARCH}', $this->script);
    }

    public function testInstallServarrAppCreatesConfigXml(): void
    {
        $this->assertStringContainsString('<Config>', $this->script);
        $this->assertTrue(
            strpos($this->script, '<BindAddress>*</BindAddress>') === false,
            'Servarr defaults must not bind wildcard address'
        );
    }

    public function testInstallServarrAppSetsUrlBasePublic(): void
    {
        $this->assertStringContainsString('/public-${USERNAME}/${app}</UrlBase>', $this->script);
    }

    public function testInstallServarrAppSetsBindAddressLoopback(): void
    {
        $this->assertStringContainsString('<BindAddress>127.0.0.1</BindAddress>', $this->script);
    }

    public function testCloudplowUsesBinDir(): void
    {
        $this->assertStringContainsString('$HOME/.bin/cloudplow', $this->script);
    }

    public function testVenvPipBootstrapUsesPython3(): void
    {
        $this->assertTrue(
            substr_count($this->script, 'python3 -m pip install -U pip >/dev/null 2>&1') === 2,
            'Cloudplow and SABnzbd venv bootstrap should use python3 explicitly'
        );
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

    public function testJellyfinFfmpegFallbackInstallsUserLocalStaticBuild(): void
    {
        $this->assertStringContainsString('JELLYFIN_STATIC_FFMPEG_AMD64_URL=', $this->script);
        $this->assertStringContainsString('install_jellyfin_static_ffmpeg_if_needed', $this->script);
        $this->assertStringContainsString('cp "$ffmpeg_src" "$HOME/.bin/ffmpeg"', $this->script);
        $this->assertStringContainsString('cp "$ffprobe_src" "$HOME/.bin/ffprobe"', $this->script);
        $this->assertStringContainsString('${JELLYFIN_STATIC_FFMPEG_URL:-}', $this->script);
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

    public function testManagedBinPathsRefreshInPlace(): void
    {
        $this->assertStringContainsString('Keeping existing ~/.bin contents outside PMSS-managed app paths.', $this->script);
        $this->assertStringContainsString('managed_install_path_reset', $this->script);
        $this->assertTrue(
            strpos($this->script, 'rm -rf "$HOME/.bin"') === false,
            'Installer must not delete the entire ~/.bin directory on reruns'
        );
    }

    public function testJellyfinPromptExplainsDataLoss(): void
    {
        $this->assertStringContainsString('Jellyfin users, metadata, and watch state will be lost.', $this->script);
    }

    public function testLighttpdArrPathsRedirectToTrailingSlash(): void
    {
        $this->assertStringContainsString('"^/radarr$" => "/public-${USERNAME}/radarr/"', $this->script);
        $this->assertStringContainsString('"^/sonarr$" => "/public-${USERNAME}/sonarr/"', $this->script);
        $this->assertStringContainsString('"^/prowlarr$" => "/public-${USERNAME}/prowlarr/"', $this->script);
    }

    public function testLighttpdMediaStackFragmentOwnsAppResponsePathMapping(): void
    {
        $this->assertStringContainsString('Location and Set-Cookie Path rewriting belongs here via', $this->script);
        $this->assertStringContainsString('map-urlpath so nginx stays a minimal per-user front door.', $this->script);
        $this->assertStringContainsString('"/sabnzbd" => "/public-${USERNAME}/sabnzbd"', $this->script);
        $this->assertStringContainsString('"/radarr" => "/public-${USERNAME}/radarr"', $this->script);
        $this->assertStringContainsString('"/prowlarr" => "/public-${USERNAME}/prowlarr"', $this->script);
        $this->assertStringContainsString('"/sonarr" => "/public-${USERNAME}/sonarr"', $this->script);
        $this->assertStringContainsString('"/jellyfin" => "/public-${USERNAME}/jellyfin"', $this->script);
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
        $this->assertStringContainsString('for app in sabnzbd radarr prowlarr sonarr jellyfin cloudplow; do', $this->script);
        $this->assertStringContainsString('tmux kill-session -t "${app}"', $this->script);
    }

    public function testSourceBashrcIsFailSoft(): void
    {
        $this->assertStringContainsString('source "$HOME/.bashrc" || true', $this->script);
    }

    public function testJellyfinSedDoesNotUseSlashDelimitersWithClosingTags(): void
    {
        $this->assertTrue(strpos($this->script, 's/\\(<PublicHttpPort>\\)') === false, 'Must not use / delimiters that break on </tag>');
        $this->assertStringContainsString('s|(<PublicHttpPort>)[^<]*(</PublicHttpPort>)|', $this->script);
        $this->assertStringContainsString('s|(<InternalHttpPort>)[^<]*(</InternalHttpPort>)|', $this->script);
    }
}
