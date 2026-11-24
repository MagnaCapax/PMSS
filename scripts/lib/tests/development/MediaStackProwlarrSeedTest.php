<?php
/** Verify Prowlarr installer wiring and fail-soft seeding with account-local HTTP stubs. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class MediaStackProwlarrSeedTest extends TestCase
{
    public function testInstallerSeedsAfterStartupAndDeliversHelper(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $this->assertStringContainsString('media_stack_verify_sessions'."\n\tif [[ -r \"\$HOME/bin/mediaStackProwlarr.sh\" ]]", $source);
        $this->assertStringContainsString('[dry-run] would add missing Prowlarr', $source);
        $this->assertStringContainsString("'bin/mediaStackProwlarr.sh'",
            $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php'));
    }

    public function testBothLinksUsePrivateFilesAndLeaveNoWorkspaceOrLoggedKeys(): void
    {
        [$home, $output] = $this->seed();
        foreach (['Radarr', 'Sonarr'] as $app) {
            $this->assertTrue(file_exists($home.'/'.$app.'.json'));
            $this->assertStringContainsString("$app application added with full sync", $output);
        }
        $this->assertSame([], glob($home.'/tmp/*'));
        $this->assertStringNotContainsString(str_repeat('a', 32), $output.file_get_contents($home.'/argv'));
        $this->assertStringContainsString('rc:0', $output);
    }

    public function testUnavailableOrUnprotectedSiblingDoesNotPreventOtherLink(): void
    {
        foreach (['radarr-offline', 'radarr-missing', 'radarr-unprotected'] as $mode) {
            [$home, $output] = $this->seed($mode);
            $this->assertFalse(file_exists($home.'/Radarr.json'));
            $this->assertTrue(file_exists($home.'/Sonarr.json'));
            $this->assertStringContainsString('Radarr link skipped', $output);
            $this->assertStringContainsString('rc:0', $output);
            $this->assertSame([], glob($home.'/tmp/*'));
        }
    }

    public function testExistingRenamedLinkIsPreservedAndMissingSiblingIsAdded(): void
    {
        [$home, $output] = $this->seed('existing');
        $this->assertFalse(file_exists($home.'/Radarr.json'));
        $this->assertTrue(file_exists($home.'/Sonarr.json'));
        $this->assertStringContainsString('Radarr application already exists; preserving settings', $output);
    }

    public function testBadListingsAndUnavailableProwlarrNeverPostLinks(): void
    {
        foreach (['prowlarr-offline', 'list-failed', 'list-malformed', 'schema-failed'] as $mode) {
            [$home, $output] = $this->seed($mode);
            $this->assertFalse(file_exists($home.'/Radarr.json'));
            $this->assertFalse(file_exists($home.'/Sonarr.json'));
            $this->assertStringContainsString('rc:0', $output);
            $this->assertSame([], glob($home.'/tmp/*'));
        }
    }

    public function testRejectedAndUncertainSavesWarnWithoutRetryOrAbort(): void
    {
        foreach (['400', '401', '500', '000'] as $code) {
            [$home, $output] = $this->seed('fresh', $code);
            $this->assertSame(2, substr_count($output, "link not confirmed (HTTP $code)"));
            $this->assertSame(2, substr_count(file_get_contents($home.'/argv'), '-X POST'));
            $this->assertStringContainsString('rc:0', $output);
            $this->assertSame([], glob($home.'/tmp/*'));
        }
    }

    private function seed(string $mode = 'fresh', string $code = '201'): array
    {
        $home = $this->pmssMakeTempDir('pmss-prowlarr-seed-').'/fixtur01';
        foreach (['radarr', 'sonarr', 'prowlarr'] as $app) {
            $this->pmssWriteRelativeFile($home, '.config/'.$app.'/config.xml', '<Config><ApiKey>'.str_repeat('a', 32)
                .'</ApiKey><UrlBase>/public-fixtur01/'.$app.'</UrlBase><AuthenticationMethod>Forms</AuthenticationMethod>'
                .'<AuthenticationRequired>Enabled</AuthenticationRequired></Config>');
        }
        $this->pmssWriteRelativeFile($home, 'tmp/.keep', '');
        unlink($home.'/tmp/.keep');
        if ($mode === 'radarr-missing') unlink($home.'/.config/radarr/config.xml');
        if ($mode === 'radarr-unprotected') file_put_contents($home.'/.config/radarr/config.xml', '<Config/>');
        $schema = [];
        foreach (['Radarr', 'Sonarr'] as $app) {
            $schema[] = ['implementation' => $app, 'configContract' => $app.'Settings', 'fields' => [
                ['name' => 'baseUrl'], ['name' => 'prowlarrUrl'], ['name' => 'apiKey']]];
        }
        $this->pmssWriteRelativeFile($home, 'schema', json_encode($schema));
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $harness = "#!/usr/bin/env bash\nset -euo pipefail\nexport HOME=".escapeshellarg($home)
            .' TMPDIR='.escapeshellarg($home.'/tmp').' FIXTURE_MODE='.escapeshellarg($mode)
            .' FIXTURE_CODE='.escapeshellarg($code)."\nPROWLARR_PORT=23003 RADARR_PORT=23001 SONARR_PORT=23002 MEDIA_STACK_APP_START_WAIT=1\n"
            .$this->pmssExtractShellFunctions($source, ['servarr_api_key_curl_config_write',
                'servarr_config_auth_configured', 'servarr_host_config_url'])."\n"
            .$this->pmssReadRepoFile('etc/skel/bin/mediaStackProwlarr.sh')."\n";
        $harness .= <<<'BASH'
log_warn() { echo "$*"; }
log_info() { echo "$*"; }
log_ok() { echo "$*"; }
media_stack_wait_http_ok() {
    [[ $(stat -c %a "$4") == 600 ]] && grep -q 'noproxy = "\*"' "$4" || return 1
    case "$FIXTURE_MODE:$1" in prowlarr-offline:*|radarr-offline:*/radarr/*) return 1 ;; esac
}
curl() {
    local url='' output='' config=''
    while [[ $# -gt 0 ]]; do
        case "$1" in
        -o) output="$2"; shift 2 ;;
        --config) config="$2"; shift 2 ;;
        --max-time) shift 2 ;;
        -fsS) shift ;;
        *) url="$1"; shift ;;
        esac
    done
    [[ $(stat -c %a "$config") == 600 ]] || return 1
    case "$url" in
    */schema) [[ "$FIXTURE_MODE" != schema-failed ]] || return 1; cp "$HOME/schema" "$output" ;;
    */applications)
        [[ "$FIXTURE_MODE" != list-failed ]] || return 1
        case "$FIXTURE_MODE" in
        existing) echo '[{"implementation":"Radarr","name":"Custom films","syncLevel":"disabled"}]' >"$output" ;;
        list-malformed) echo '{}' >"$output" ;;
        *) echo '[]' >"$output" ;;
        esac ;;
    *) return 1 ;;
    esac
}
media_stack_http_code() {
    printf '%s\n' "$*" >>"$HOME/argv"
    local payload="${@: -3:1}" app
    [[ $(stat -c %a "${payload#@}") == 600 ]] || { printf 000; return; }
    app=$(php -r 'echo json_decode(file_get_contents($argv[1]))->implementation;' "${payload#@}")
    cp "${payload#@}" "$HOME/$app.json"
    printf '%s' "$FIXTURE_CODE"
}
if media_stack_prowlarr_seed; then echo rc:0; else echo rc:1; fi
BASH;
        return [$home, $this->pmssRunShellHarness($harness)];
    }
}
