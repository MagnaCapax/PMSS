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

    public function testServarrConfigDisablesInPlaceUpdates(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-stack-update-policy-home-');
        $existingConfig = $home.'/existing';
        $this->pmssEnsureDir($existingConfig);
        $this->pmssWriteFile(
            $existingConfig.'/config.xml',
            "<Config>\n  <UpdateMechanism>BuiltIn</UpdateMechanism>\n  <UpdateAutomatically>True</UpdateAutomatically>\n</Config>\n"
        );

        $functions = $this->pmssExtractShellFunctions($this->script, array(
            'servarr_config_xml_tag_converge',
            'servarr_config_xml_converge',
        ));
        $script = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'HOME='.escapeshellarg($home),
            'USERNAME=alice',
            'DRY_RUN=0',
            'log_info() { :; }',
            $functions,
            'mkdir -p "$HOME/fresh"',
            'servarr_config_xml_converge radarr "$HOME/fresh" 17878 7878',
            'servarr_config_xml_converge radarr "$HOME/existing" 17879 7879',
            'cat "$HOME/fresh/config.xml"',
            'cat "$HOME/existing/config.xml"',
            '',
        ));

        $output = $this->pmssRunShellHarness($script);

        $this->assertSame(2, substr_count($output, '<UpdateMechanism>External</UpdateMechanism>'));
        $this->assertSame(2, substr_count($output, '<UpdateAutomatically>False</UpdateAutomatically>'));
        $this->assertSame(2, substr_count($output, '<AuthenticationRequired>Enabled</AuthenticationRequired>'));
        $this->assertStringNotContainsString('<UpdateMechanism>BuiltIn</UpdateMechanism>', $output);
        $this->assertStringNotContainsString('<UpdateAutomatically>True</UpdateAutomatically>', $output);
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

    public function testSabnzbdConfiguresAuthAndKeepsProxiedWebUiAccess(): void
    {
        $this->assertStringContainsAllStrings([
            'inet_exposure = 4',
            'sabnzbd_misc_value_set() {',
            'sabnzbd_misc_value_set "$datadir/${app}.ini" username "$SABNZBD_AUTH_USERNAME"',
            'sabnzbd_misc_value_set "$datadir/${app}.ini" password "$SABNZBD_PASSWORD"',
            'sabnzbd_misc_value_set "$datadir/${app}.ini" inet_exposure "4"',
        ], $this->script);
    }

    public function testManagedAppShellPathMarkersPresent(): void
    {
        $this->assertStringContainsAllStrings([
            '$HOME/.bin/cloudplow',
            '$HOME/.bin/autobrr',
            '$HOME/.config/sabnzbd',
            '$HOME/.config/autobrr',
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

    public function testAutobrrRuntimeUsesLoopbackAndSubpathSettings(): void
    {
        $this->assertStringContainsAllStrings([
            'AUTOBRR__HOST=127.0.0.1',
            'AUTOBRR__BASE_URL=/autobrr/',
            'AUTOBRR__BASE_URL_MODE_LEGACY=true',
            '--config=\\"$HOME/.config/autobrr\\"',
        ], $this->script);
    }

    public function testMediaStackCredentialsUseDelugeStylePhpCsprngAndOwnerOnlyFile(): void
    {
        $this->assertStringContainsAllStrings([
            'MEDIA_STACK_CREDENTIALS_FILE="$HOME/.media-stack-credentials.txt"',
            'media_stack_password_generate() {',
            'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_',
            'random_int(0, $maxIndex)',
            'media_stack_credentials_file_write() {',
            'mktemp "${MEDIA_STACK_CREDENTIALS_FILE}.pmss.XXXXXX"',
            'chmod 600 "$MEDIA_STACK_CREDENTIALS_FILE"',
            'media_stack_credentials_summary_print',
            'SABNZBD_PASSWORD = %s',
            'RADARR_PASSWORD = %s',
            'JELLYFIN_PASSWORD = %s',
        ], $this->script);
    }

    public function testServarrAuthSeedingUsesLocalApiAndFailsClosed(): void
    {
        $this->assertStringContainsAllStrings([
            'servarr_auth_seed() {',
            'base_url="http://127.0.0.1:${seed_port}/api/${api_version}/config/host"',
            '$data["authenticationMethod"] = "forms"',
            '$data["authenticationRequired"] = "enabled"',
            'servarr_credentials_mark_existing_unknown() {',
            'RADARR_PASSWORD="[existing Radarr password preserved; not known to installer]"',
            'password not changed',
            'servarr_config_xml_tag_converge "$config_file" AuthenticationMethod Forms',
            'servarr_config_xml_tag_converge "$config_file" AuthenticationRequired Enabled',
            'unauthenticated API returned HTTP ${unauth_code}',
            'Radarr auth seeding failed; not starting media stack',
            'Prowlarr auth seeding failed; not starting media stack',
            'Sonarr auth seeding failed; not starting media stack',
        ], $this->script);
    }

    public function testMediaStackWaitHttpOkReadyFailFastAndTimeout(): void
    {
        // Exercises the live readiness path (previously only string-asserted):
        //   ready  -> rc 0 once curl answers 2xx
        //   death  -> rc 2 immediately when the tmux session is gone (fail fast)
        //   budget -> rc 1 after the attempt budget with no session to watch
        $function = $this->pmssExtractShellFunction($this->script, 'media_stack_wait_http_ok');
        $bin = $this->pmssMakeTempDir('pmss-media-wait-bin-');

        // curl mock: fails until it has been called more than $READY_AFTER times.
        $this->pmssWriteExecutableFile($bin.'/curl', <<<'BASH'
#!/usr/bin/env bash
state="${STATE:-/dev/null}"
ready_after="${READY_AFTER:-999999}"
count=0
[[ -f "$state" ]] && count=$(cat "$state")
count=$((count + 1))
[[ "$state" != /dev/null ]] && echo "$count" > "$state"
[[ "$count" -gt "$ready_after" ]] && exit 0
exit 1
BASH
        );
        // tmux mock: has-session exit is $SESSION_ALIVE (0 alive, 1 gone); all else ok.
        $this->pmssWriteExecutableFile($bin.'/tmux', <<<'BASH'
#!/usr/bin/env bash
[[ "$1" == "has-session" ]] && exit "${SESSION_ALIVE:-0}"
exit 0
BASH
        );
        // sleep mock: no-op so the budget run does not actually wait.
        $this->pmssWriteExecutableFile($bin.'/sleep', "#!/usr/bin/env bash\nexit 0\n");

        $run = function (string $body) use ($function, $bin): string {
            $harness = implode("\n", array(
                '#!/usr/bin/env bash',
                'set -euo pipefail',
                'PATH='.escapeshellarg($bin).':$PATH',
                $function,
                $body,
                '',
            ));
            return $this->pmssRunShellHarness($harness);
        };

        $stateReady = $this->pmssMakeTempDir('pmss-media-wait-s1-').'/s';
        $this->assertStringContainsString('rc=0', $run(
            'export STATE='.escapeshellarg($stateReady).' READY_AFTER=2'."\n"
            .'rc=0; media_stack_wait_http_ok http://x 10 "" || rc=$?; echo "rc=$rc"'
        ), 'wait must report ready (rc 0) once curl answers 2xx');

        $this->assertStringContainsString('rc=2', $run(
            'export SESSION_ALIVE=1'."\n"
            .'rc=0; media_stack_wait_http_ok http://x 999 seed-session || rc=$?; echo "rc=$rc"'
        ), 'wait must fail fast (rc 2) when the tmux session has exited, not exhaust the budget');

        $this->assertStringContainsString('rc=1', $run(
            'rc=0; media_stack_wait_http_ok http://x 3 "" || rc=$?; echo "rc=$rc"'
        ), 'wait must time out (rc 1) after the budget when there is no session to watch');
    }

    public function testAutobrrAuthSeedingUsesCliAndPreservesExistingUsers(): void
    {
        $this->assertStringContainsAllStrings([
            'autobrr_auth_seed() {',
            'AUTOBRR__PORT=\"$seed_port\"',
            'autobrrctl" --config "$datadir" create-user "$MEDIA_STACK_AUTH_USERNAME"',
            'Autobrr user already exists or could not be changed; preserving existing Autobrr credentials',
            'Autobrr auth seeding failed; not starting media stack',
        ], $this->script);
    }

    public function testJellyfinStartupWizardAdminIsSeededAndVerified(): void
    {
        $this->assertStringContainsAllStrings([
            'jellyfin_auth_seed() {',
            '${base_url}/Startup/User',
            '${base_url}/Startup/Complete',
            '${base_url}/Users/AuthenticateByName',
            'Authorization: MediaBrowser Client="PMSS"',
            'Jellyfin admin login verification failed',
            'Jellyfin auth seeding failed; not starting media stack',
        ], $this->script);
    }

    public function testJellyfinLibraryPathGuidanceIsPrinted(): void
    {
        $this->assertStringContainsAllStrings([
            'echo "JELLYFIN-MEDIA-PATH = $HOME/data"',
            'echo "JELLYFIN-LIBRARY-GUIDANCE = Jellyfin cannot list /home on PMSS;',
            'type the full path above into the folder field instead of selecting /home."',
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
        $preflight = strpos($this->script, "\n\tjellyfin_ffmpeg_configure_fallback\n");
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

    public function testUninstallRemovesRuntimeArtifactsAndPreservesCredentials(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-stack-uninstall-home-');
        $bin = $this->pmssMakeTempDir('pmss-media-stack-uninstall-bin-');
        $this->pmssWriteFile($home.'/.install-media-stack.log', "old installer output\n");
        $this->pmssWriteFile($home.'/.media-stack-status.json', "{\"state\":\"failed\"}\n");
        $this->pmssWriteFile($home.'/.media-stack-credentials.txt', "preserve\n");
        foreach (array('tmux', 'pkill') as $command) {
            $this->pmssWriteExecutableFile($bin.'/'.$command, "#!/usr/bin/env bash\nexit 0\n");
        }

        $script = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'export HOME='.escapeshellarg($home),
            'export PATH='.escapeshellarg($bin).':/usr/bin:/bin',
            '/bin/bash '.escapeshellarg($this->pmssRepoPath('etc/skel/install-media-stack.sh')).' --uninstall',
            '',
        ));

        $output = $this->pmssRunShellHarness($script);

        $this->assertStringContainsString('PMSS media stack uninstall complete.', $output);
        $this->assertFalse(file_exists($home.'/.install-media-stack.log'));
        $this->assertFalse(file_exists($home.'/.media-stack-status.json'));
        $this->assertSame("preserve\n", (string) file_get_contents($home.'/.media-stack-credentials.txt'));
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
            'SABNZBD_PORT=18080 RADARR_PORT=17878 PROWLARR_PORT=19696 SONARR_PORT=18989 AUTOBRR_PORT=17474 JELLYFIN_PORT=18096',
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
            'Location rewriting belongs here via map-urlpath. Set-Cookie',
            'Path rewriting stays in nginx proxy_cookie_path rules',
            'map-urlpath does not rewrite Set-Cookie.',
            '"^/autobrr$" => "/public-alice/autobrr/"',
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
            '$HTTP["url"] =~ "^/autobrr(\$|/)" {',
            '"port" => 17474',
            '"/autobrr" => ""',
            '$HTTP["url"] =~ "^/jellyfin(\$|/)" {',
            '"port" => 18096',
            '"/jellyfin" => "/public-alice/jellyfin"',
        ), $withJellyfin);
        $this->assertSame(6, substr_count($withJellyfin, '"upgrade" => "enable"'));
        $this->assertSame(5, substr_count($withoutJellyfin, '"upgrade" => "enable"'));
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

    public function testStartStoppedModeUsesAliasesAndSkipsLiveSessions(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-start-stopped-');
        $bin = $this->pmssMakeTempDir('pmss-media-start-stopped-bin-');
        $this->pmssWriteExecutableFile($bin.'/tmux', <<<'BASH'
#!/usr/bin/env bash
if [[ "$1" == "has-session" && "$3" == "radarr" ]]; then
    exit 0
fi
if [[ "$1" == "has-session" ]]; then
    exit 1
fi
printf '%s\n' "$*" >> "$HOME/tmux-actions"
BASH
        );
        $this->pmssWriteFile($home.'/.bashrc.custom', <<<'BASHRC'
alias sonarr='tmux new-session -d -s sonarr true'
alias radarr='tmux new-session -d -s radarr true'
BASHRC
        );
        $function = $this->pmssExtractShellFunction($this->script, 'media_stack_start_stopped');
        $harness = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'HOME='.escapeshellarg($home),
            'PATH='.escapeshellarg($bin).':$PATH',
            'MEDIA_STACK_BASE_SESSIONS=(sonarr radarr prowlarr sabnzbd cloudplow autobrr)',
            'log_ok() { echo "OK:$*"; }',
            'log_warn() { echo "WARN:$*"; }',
            'log_err() { echo "ERR:$*"; }',
            $function,
            'media_stack_start_stopped',
            'cat "$HOME/tmux-actions"',
            '',
        ));

        $output = $this->pmssRunShellHarness($harness);

        $this->assertStringContainsString('OK:Started sonarr', $output);
        $this->assertStringContainsString('new-session -d -s sonarr true', $output);
        $this->assertStringNotContainsString('new-session -d -s radarr true', $output);
    }

    public function testSecureAppModeIsScopedAndSkipsFullInstallResolution(): void
    {
        $this->assertStringContainsAllStrings([
            '--secure-app=APP',
            '--skip-update | --uninstall | --start-stopped | --secure-app=*)',
            '--secure-app=*) SECURE_APP=${arg#*=} ;;',
            'media_stack_secure_app_id_valid() {',
            'jellyfin | radarr | sonarr | prowlarr | sabnzbd | autobrr)',
            'media_stack_secure_app() {',
            'media_stack_credentials_app_write() {',
            'media_stack_stop_app_for_auth() {',
            "printf 'pmss-media-stack-secured:%s\\n' \"\$1\"",
            'if [[ -n "$SECURE_APP" ]]; then',
        ], $this->script);

        $secureSkip = strpos($this->script, 'if [[ -z "$SECURE_APP" ]]; then');
        $dependencyCheck = strpos($this->script, 'log_step "Checking dependencies..."');
        $secureDispatch = strpos($this->script, 'if [[ -n "$SECURE_APP" ]]; then');
        $fullInstallPorts = strrpos($this->script, 'SABNZBD_PORT=$(pick_existing_or_reserved_port');

        $this->assertTrue($secureSkip !== false, 'Secure mode skip guard missing');
        $this->assertTrue($dependencyCheck !== false, 'Dependency check missing');
        $this->assertTrue($secureDispatch !== false, 'Secure mode dispatch missing');
        $this->assertTrue($fullInstallPorts !== false, 'Full install port selection missing');
        $this->assertTrue($secureSkip < $dependencyCheck, 'Secure mode must skip release/dependency resolution');
        $this->assertTrue($secureDispatch < $fullInstallPorts, 'Secure mode must exit before full install port selection');
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
            'https://api.github.com/repos/autobrr/autobrr/releases/latest',
            'autobrr_resolve_download_url',
            'JF_REPO_INDEX=$(fetch_text "$JF_REPO_BASE")',
            'Could not resolve SABnzbd release metadata from GitHub',
        ], $this->script);
    }

    public function testAutobrrResolverSelectsReleaseAssetForArchitecture(): void
    {
        $functions = $this->pmssExtractShellFunctions($this->script, array('autobrr_resolve_download_url'));
        $script = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'AUTOBRR_ARCH=x86_64',
            'OVR_AUTOBRR_URL=https://mirror.example/autobrr.tar.gz',
            'log_err() { echo "ERR:$*"; }',
            $functions,
            'autobrr_resolve_download_url',
            'echo "override=$AUTOBRR_VERSION|$AUTOBRR_URL"',
            'OVR_AUTOBRR_URL=',
            'fetch_text() { printf "%s\\n" \'{\' \'  "tag_name": "v1.83.0",\' \'  "browser_download_url": "https://github.com/autobrr/autobrr/releases/download/v1.83.0/autobrr_1.83.0_linux_x86_64.tar.gz"\' \'}\'; }',
            'autobrr_resolve_download_url',
            'echo "release=$AUTOBRR_VERSION|$AUTOBRR_URL"',
            '',
        ));

        $output = $this->pmssRunShellHarness($script);

        $this->assertStringContainsAllStrings([
            'override=override|https://mirror.example/autobrr.tar.gz',
            'release=v1.83.0|https://github.com/autobrr/autobrr/releases/download/v1.83.0/autobrr_1.83.0_linux_x86_64.tar.gz',
        ], $output);
    }

    public function testAutobrrConfigPreservesExistingSettingsAndConvergesPort(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-stack-autobrr-config-');
        $newConfig = $home.'/new/config.toml';
        $existingConfig = $home.'/existing/config.toml';
        $this->pmssEnsureDir(dirname($newConfig));
        $this->pmssWriteFile($existingConfig, "host = \"127.0.0.1\"\nport = 12345\nsessionSecret = \"oldsecret\"\ncustom = true\n");
        $functions = $this->pmssExtractShellFunctions($this->script, array('autobrr_configure'));
        $script = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            $functions,
            'autobrr_configure '.escapeshellarg($newConfig).' 23456 newsecret',
            'autobrr_configure '.escapeshellarg($existingConfig).' 34567 newsecret',
            'cat '.escapeshellarg($newConfig),
            'cat '.escapeshellarg($existingConfig),
            '',
        ));

        $output = $this->pmssRunShellHarness($script);

        $this->assertStringContainsAllStrings([
            'host = "127.0.0.1"',
            'port = 23456',
            'port = 34567',
            'custom = true',
            'baseUrl = "/autobrr/"',
            'baseUrlModeLegacy = true',
            'databaseType = "sqlite"',
            'sessionSecret = "newsecret"',
            'sessionSecret = "oldsecret"',
        ], $output);
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
            'MEDIA_STACK_STOP_SESSIONS=(sabnzbd radarr prowlarr sonarr cloudplow autobrr)',
            'media_stack_sessions "${MEDIA_STACK_STOP_SESSIONS[@]}"',
            'while IFS= read -r app; do',
            'tmux kill-session -t "${app}"',
        ], $this->script);
    }

    public function testSourceBashrcIsFailSoft(): void
    {
        $this->assertStringContainsString('source "$HOME/.bashrc" || true', $this->script);
    }

    public function testPortSelectionPrefersReservationAndPreservesLegacyExistingPort(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-stack-port-selection-');
        $this->pmssWriteFile($home.'/.media-stack-port-sabnzbd', "21000\n");
        $this->pmssWriteFile($home.'/.media-stack-port-sonarr', "22000\n");
        $functions = $this->pmssExtractShellFunctions($this->script, array(
            'media_stack_port_is_valid',
            'media_stack_reserved_port_read',
            'pick_existing_or_reserved_port',
        ));
        $script = implode("\n", array(
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'HOME='.escapeshellarg($home),
            'log_warn() { echo "WARN:$*" >&2; }',
            'log_err() { echo "ERR:$*" >&2; }',
            $functions,
            'echo "reserved=$(pick_existing_or_reserved_port 8080 sabnzbd)"',
            'echo "legacy=$(pick_existing_or_reserved_port 8081 radarr)"',
            'echo "invalid=$(pick_existing_or_reserved_port abc sonarr)"',
            'set +e',
            '(pick_existing_or_reserved_port abc prowlarr >/dev/null)',
            'echo "missing_rc=$?"',
            '',
        ));

        $output = $this->pmssRunShellHarness($script);

        $this->assertStringContainsAllStrings([
            'reserved=21000',
            'legacy=8081',
            'invalid=22000',
            'missing_rc=1',
            'WARN:No PMSS reservation for radarr; preserving its existing port',
            "WARN:Ignoring invalid existing port 'abc'",
            'ERR:No PMSS-reserved port is available for prowlarr; run a full PMSS update first',
        ], $output);
        $this->assertStringNotContainsString('random_'.'open_port', $this->script);
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
