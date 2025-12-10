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

    public function testResolveServarrUrlFunctionPresent(): void
    {
        $this->assertTrue(strpos($this->script, 'resolve_servarr_url()') !== false, 'resolve_servarr_url helper missing');
    }

    public function testInstallServarrAppFunctionPresent(): void
    {
        $this->assertTrue(strpos($this->script, 'install_servarr_app()') !== false, 'install_servarr_app helper missing');
    }

    public function testResolveServarrUrlUsesRadarrUpdateBase(): void
    {
        $this->assertTrue(strpos($this->script, 'RADARR_UPDATE_BASE') !== false, 'Radarr update base not referenced');
        $this->assertStringContainsString('${RADARR_UPDATE_BASE}/${branch}/updatefile?os=linux&arch=${SERVARR_ARCH}', $this->script);
    }

    public function testResolveServarrUrlPinsRadarrForOldGlibc(): void
    {
        $this->assertStringContainsString('v5.10.4.9218', $this->script);
    }

    public function testResolveServarrUrlProwlarrRuntimeNetcore(): void
    {
        $this->assertStringContainsString('runtime=netcore&arch=${SERVARR_ARCH}', $this->script);
    }

    public function testResolveServarrUrlSonarrRuntimeNetcore(): void
    {
        $this->assertStringContainsString('latest?version=${SONARR_MAJOR}&os=linux&runtime=netcore&arch=${SERVARR_ARCH}', $this->script);
    }

    public function testInstallServarrAppCreatesConfigXml(): void
    {
        $this->assertStringContainsString('<Config>', $this->script);
        $this->assertStringContainsString('<BindAddress>*</BindAddress>', $this->script);
    }

    public function testInstallServarrAppSetsUrlBasePublic(): void
    {
        $this->assertStringContainsString('/public-${USERNAME}/${app}</UrlBase>', $this->script);
    }

    public function testInstallServarrAppSetsBindAddressLoopback(): void
    {
        $this->assertStringContainsString('<BindAddress>127.0.0.1</BindAddress>', $this->script);
    }

    public function testInstallServarrAppUsesBinRootForDownloads(): void
    {
        $this->assertStringContainsString('cd "$BIN_ROOT"', $this->script);
    }

    public function testInstallServarrAppTouchesUpdateRequired(): void
    {
        $this->assertStringContainsString('touch "$datadir"/update_required', $this->script);
    }

    public function testNoLegacyServarrBranchIdentifier(): void
    {
        $this->assertTrue(strpos($this->script, 'SERVARR_BRANCH') === false, 'Legacy SERVARR_BRANCH should not exist');
    }

    public function testCloudplowUsesBinRoot(): void
    {
        $this->assertStringContainsString('$BIN_ROOT/cloudplow', $this->script);
    }

    public function testSabnzbdUsesConfigRoot(): void
    {
        $this->assertStringContainsString('$CONFIG_ROOT/sabnzbd', $this->script);
    }

    public function testSabnzbdVersionOverrideApplied(): void
    {
        $this->assertStringContainsString('if [[ -n "$OVR_SAB_VERSION" ]]; then SABNZBD_VERSION', $this->script);
    }

    public function testDotnetRootExportedInBashrc(): void
    {
        $this->assertStringContainsString('export DOTNET_ROOT=${DOTNET_ROOT_PATH}', $this->script);
    }

    public function testBinRootPrependedToPath(): void
    {
        $this->assertStringContainsString('export PATH=${BIN_ROOT}:$DOTNET_ROOT:$PATH', str_replace('\\', '', $this->script));
    }

    public function testAliasUsesCommandVariables(): void
    {
        $this->assertTrue(strpos($this->script, 'alias sonarr=\'tmux new-session -d -s "sonarr" "') !== false, 'Sonarr alias prefix missing');
        $this->assertStringContainsString('$SONARR_CMD', $this->script);
    }

    public function testTmuxStartupUsesCommandVariables(): void
    {
        $this->assertStringContainsString('tmux new-session -d -s "sonarr" "$SONARR_CMD"', $this->script);
    }

    public function testJellyfinPathsUseCentralDirs(): void
    {
        $clean = str_replace('\\', '', $this->script);
        $this->assertStringContainsString('export JELLYFIN_CONFIG_DIR="${JELLYFIN_DATA_DIR}"', $clean);
        $this->assertStringContainsString('export JELLYFIN_LOG_DIR="${JELLYFIN_LOG_DIR}"', $clean);
    }

    public function testLighttpdCustomConfigExists(): void
    {
        $this->assertStringContainsString('/.lighttpd/custom', $this->script);
    }

    public function testDryRunLoggingPresent(): void
    {
        $this->assertStringContainsString('[dry-run]', $this->script);
    }

    public function testVerifyOnlyLoggingPresent(): void
    {
        $this->assertStringContainsString('URL verification mode enabled', $this->script);
    }

    public function testFetchUsesCheckUrlOnDryRun(): void
    {
        $this->assertStringContainsString('if [[ $DRY_RUN -eq 1 ]]; then', $this->script);
        $this->assertStringContainsString('check_url "$url"', $this->script);
    }

    public function testCheckUrlUsesCurlOrWget(): void
    {
        $this->assertStringContainsString('curl -fsIL "$url"', $this->script);
        $this->assertStringContainsString('wget -q --spider "$url"', $this->script);
    }

    public function testLogFilePathSetOnce(): void
    {
        $this->assertStringContainsString('LOG_FILE="$HOME/.install-media-stack.log"', $this->script);
    }

    public function testColorVariablesDefined(): void
    {
        $this->assertStringContainsString('C_RESET="\\033[0m"', $this->script);
    }

    public function testAspNetPathExported(): void
    {
        $clean = str_replace('\\', '', $this->script);
        $this->assertStringContainsString('export DOTNET_ROOT=${DOTNET_ROOT_PATH}', $clean);
        $this->assertStringContainsString('export PATH=${BIN_ROOT}:$DOTNET_ROOT:$PATH', $clean);
    }

    public function testJellyfinSystemXmlBaseUrlUpdated(): void
    {
        $this->assertStringContainsString('<BaseUrl>/public-${USERNAME}/${app}</BaseUrl>', $this->script);
    }

    public function testJellyfinSystemXmlPortUpdated(): void
    {
        $this->assertStringContainsString('<PublicPort>$JELLYFIN_PORT</PublicPort>', $this->script);
        $this->assertStringContainsString('<HttpServerPortNumber>$JELLYFIN_PORT</HttpServerPortNumber>', $this->script);
    }
}
