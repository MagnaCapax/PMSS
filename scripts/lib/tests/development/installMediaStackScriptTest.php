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

    public function testCloudplowUsesBinDir(): void
    {
        $this->assertStringContainsString('$HOME/.bin/cloudplow', $this->script);
    }

    public function testSabnzbdUsesConfigDir(): void
    {
        $this->assertStringContainsString('$HOME/.config/sabnzbd', $this->script);
    }

    public function testDotnetRootExportedInBashrc(): void
    {
        $this->assertStringContainsString('export DOTNET_ROOT=$HOME/.bin/dotnet', $this->script);
    }

    public function testBinDirPrependedToPath(): void
    {
        $this->assertStringContainsString('export PATH=$HOME/.bin:$DOTNET_ROOT:$PATH', $this->script);
    }

    public function testLighttpdCustomConfigExists(): void
    {
        $this->assertStringContainsString('/.lighttpd/custom', $this->script);
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
        $this->assertStringContainsString('curl -fsIL "$url"', $this->script);
        $this->assertStringContainsString('wget -q --spider "$url"', $this->script);
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
        $this->assertStringContainsString('tmux kill-session -t "${app}"', $this->script);
        $this->assertStringContainsString('tmux kill-session -t "jellyfin"', $this->script);
    }

    public function testSourceBashrcIsFailSoft(): void
    {
        $this->assertStringContainsString('source "$HOME/.bashrc" || true', $this->script);
    }

    public function testJellyfinSedDoesNotUseSlashDelimitersWithClosingTags(): void
    {
        $this->assertTrue(strpos($this->script, 's/\\(<PublicPort>\\)') === false, 'Must not use / delimiters that break on </tag>');
        $this->assertStringContainsString('s|(<PublicPort>)[^<]*(</PublicPort>)|', $this->script);
    }
}
