<?php
/** Verify fresh-only Radarr defaults in etc/skel/install-media-stack.sh without starting apps. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class MediaStackRadarrInstallTest extends TestCase
{
    public function testFreshDefaultsReuseTheVerifiedPrivateStartBeforeCleanup(): void
    {
        [$home, $output] = $this->seed();
        $trace = file_get_contents($home.'/trace');
        $this->assertOrderedStrings(['start', 'verify', 'defaults', 'cleanup', 'UpdateMechanism=Script'], $trace);
        $this->assertSame(1, substr_count($trace, 'start'));
        $this->assertStringContainsString('rc:0', $output);
    }

    public function testExistingDatabaseAndDanglingLinkNeverReceiveDefaults(): void
    {
        foreach (['existing', 'db-link', 'protected', 'other-app'] as $mode) {
            [$home, $output] = $this->seed($mode);
            $this->assertStringNotContainsString('defaults', file_get_contents($home.'/trace'));
            $this->assertStringContainsString('rc:0', $output);
            if ($mode === 'existing') $this->assertSame('customer bytes', file_get_contents($home.'/.config/radarr/radarr.db'));
            if ($mode === 'db-link') $this->assertTrue(is_link($home.'/.config/radarr/radarr.db'));
        }
    }

    public function testAuthFailuresNeverSeedDefaultsAndStillCleanUp(): void
    {
        foreach (['key-failure', 'auth-failure'] as $mode) {
            [$home, $output] = $this->seed($mode);
            $this->assertStringNotContainsString('defaults', file_get_contents($home.'/trace'));
            $this->assertStringContainsString('cleanup', file_get_contents($home.'/trace'));
            $this->assertStringContainsString('rc:1', $output);
        }
    }

    public function testOptionalDefaultsFailureAndMissingHelperRetainSecuredStartup(): void
    {
        foreach (['helper-failure', 'missing-helper'] as $mode) {
            [$home, $output] = $this->seed($mode);
            $this->assertStringContainsAllStrings(['rc:0', 'add ~/data/Movies'], $output);
            $this->assertStringContainsString('UpdateMechanism=Script', file_get_contents($home.'/trace'));
        }
    }

    public function testUpdaterDeliversTheAccountLocalHelper(): void
    {
        $this->assertStringContainsString("'bin/mediaStackRadarr.sh'",
            $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php'));
    }

    /** Run the real auth orchestration with fixture-only process, HTTP and config operations. */
    private function seed(string $mode = ''): array
    {
        $home = $this->pmssMakeTempDir('pmss-radarr-install-').'/fixtur01';
        $app = $mode === 'other-app' ? 'sonarr' : 'radarr';
        $this->pmssWriteRelativeFile($home, '.config/'.$app.'/config.xml', '<Config/>');
        $this->pmssWriteRelativeFile($home, 'tmp/.keep', '');
        unlink($home.'/tmp/.keep');
        $this->pmssWriteRelativeFile($home, 'trace', '');
        if ($mode === 'existing') $this->pmssWriteRelativeFile($home, '.config/radarr/radarr.db', 'customer bytes');
        if ($mode === 'db-link') symlink($home.'/absent', $home.'/.config/radarr/radarr.db');
        if ($mode !== 'missing-helper') {
            $this->pmssWriteRelativeFile($home, 'bin/mediaStackRadarr.sh', <<<'BASH'
media_stack_radarr_defaults_seed() {
    [[ "$PMSS_MEDIA_STACK_SEED_PRIVATE" == 1 && -f "$HOME/.config/radarr/radarr.db" ]] || return 1
    echo defaults >>"$HOME/trace"
    [[ "$MODE" != helper-failure ]]
}
BASH
            );
        }
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $shell = "#!/usr/bin/env bash\nset -euo pipefail\nexport HOME=".escapeshellarg($home)
            .' TMPDIR='.escapeshellarg($home.'/tmp')."\nMODE=".escapeshellarg($mode)."\n"
            .$this->pmssExtractShellFunction($source, 'servarr_auth_seed')."\n";
        $shell .= <<<'BASH'
PMSS_MEDIA_STACK_SEED_PRIVATE=1 MEDIA_STACK_APP_START_WAIT=1 DOTNET_ROOT_PATH=/fixture/dotnet MEDIA_STACK_AUTH_USERNAME=fixtur01
servarr_config_auth_configured() { [[ "$MODE" == protected ]]; }
media_stack_credentials_value() { echo fixture-service-password; }
media_stack_app_key() { echo RADARR; }
servarr_host_config_url() { echo http://127.0.0.1:23001/public-fixtur01/radarr/api/v3/config/host; }
servarr_config_xml_tag_converge() { printf '%s=%s\n' "$2" "$3" >>"$HOME/trace"; }
media_stack_session_kill_owned() { :; }
tmux() {
    echo start >>"$HOME/trace"
    local db="$HOME/.config/$app/$app.db"
    [[ -e "$db" || -L "$db" ]] || printf 'initialized' >"$db"
}
servarr_api_key_curl_config_wait() { [[ "$MODE" != key-failure ]]; }
media_stack_wait_http_ok() { :; }
curl() { printf '{}' >"${@: -1}"; }
servarr_auth_payload_write() { printf '{}' >"$2"; }
media_stack_credentials_app_write() { :; }
media_stack_http_code() {
    if [[ "$*" == *'-X PUT'* ]]; then printf 200; return; fi
    echo verify >>"$HOME/trace"
    if [[ "$MODE" == auth-failure ]]; then printf 200; else printf 401; fi
}
servarr_auth_seed_cleanup() { echo cleanup >>"$HOME/trace"; rm -f "$2" "$3" "$4"; }
sleep() { :; }
log_info() { echo "$*"; }
log_ok() { echo "$*"; }
log_err() { echo "$*" >&2; }
log_warn() { echo "$*" >&2; }
BASH;
        $shell .= "\nif servarr_auth_seed ".$app." ".ucfirst($app)." ".ucfirst($app).".dll 23001 v3 fixture-secret; then echo rc:0; else echo rc:$?; fi\n";
        $output = $this->pmssRunShellHarness($shell);
        $this->assertSame([], glob($home.'/tmp/*'));
        return [$home, $output];
    }
}
