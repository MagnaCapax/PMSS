<?php
/** Verify private fresh-Radarr root seeding with account-local HTTP fixtures. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class MediaStackRadarrDefaultsTest extends TestCase
{
    public function testEmptyInstallationCreatesAndRegistersTheMovieRoot(): void
    {
        [$home, $output] = $this->seed();
        $this->assertTrue(is_dir($home.'/data/Movies'));
        $this->assertSame(['path' => $home.'/data/Movies'], json_decode(file_get_contents($home.'/saved'), true));
        $this->assertStringContainsAllStrings(['rc:0', 'Radarr movie root added'], $output);
        $this->assertStringContainsString('-X POST', file_get_contents($home.'/argv'));
        $this->assertStringNotContainsString(str_repeat('a', 32), $output.file_get_contents($home.'/argv'));
    }

    public function testExistingRootsArePreservedWithoutCreatingFoldersOrPosting(): void
    {
        foreach (['[{"path":"/fixture/custom"}]', '[{"path":"/fixture/data/Movies"},{"path":"/fixture/other"}]'] as $roots) {
            [$home, $output] = $this->seed($roots);
            $this->assertFalse(is_dir($home.'/data'));
            $this->assertFalse(file_exists($home.'/saved'));
            $this->assertStringContainsAllStrings(['preserving settings', 'rc:0'], $output);
        }
    }

    public function testMalformedAndNonListRepliesNeverAuthorizeAWrite(): void
    {
        foreach (['', '{broken', '{}', 'null', '[null]', '[{}]', '[{"path":[]}]', '[{"path":""}]',
            '[{"path":"/fixture/custom"},null]'] as $roots) {
            [$home, $output] = $this->seed($roots);
            $this->assertFalse(is_dir($home.'/data'));
            $this->assertFalse(file_exists($home.'/saved'));
            $this->assertStringContainsString('rc:1', $output);
        }
    }

    public function testReadAndDirectoryFailuresNeverPostAndAlwaysCleanUp(): void
    {
        foreach (['read-failure', 'mkdir-failure', 'shared-network'] as $mode) {
            [$home, $output] = $this->seed('[]', $mode);
            $this->assertFalse(file_exists($home.'/saved'));
            $this->assertStringContainsString('rc:1', $output);
        }
    }

    public function testRejectedAndUncertainPostsAreNotRetried(): void
    {
        foreach (['000', '200', '307', '400', '401', '500'] as $code) {
            [$home, $output] = $this->seed('[]', '', $code);
            $this->assertSame(1, substr_count(file_get_contents($home.'/argv'), '-X POST'));
            $this->assertStringContainsString('rc:1', $output);
            $this->assertStringNotContainsString('movie root added', $output);
        }
    }

    /** These stubs run only on the testing VM; every file belongs to its private fixture. */
    private function seed(string $roots = '[]', string $mode = '', string $code = '201'): array
    {
        $home = $this->pmssMakeTempDir('pmss-radarr-defaults-').'/fixture home';
        $this->pmssWriteRelativeFile($home, 'tmp/.keep', '');
        unlink($home.'/tmp/.keep');
        $this->pmssWriteRelativeFile($home, 'curl', 'header = "X-Api-Key: '.str_repeat('a', 32).'"');
        if ($mode === 'mkdir-failure') $this->pmssWriteRelativeFile($home, 'data', 'occupied');
        $shell = "#!/usr/bin/env bash\nset -euo pipefail\nexport HOME=".escapeshellarg($home)
            .' TMPDIR='.escapeshellarg($home.'/tmp')."\nROOT_RESPONSE=".escapeshellarg($roots)
            .' FIXTURE_MODE='.escapeshellarg($mode).' FIXTURE_CODE='.escapeshellarg($code)."\n"
            .'PMSS_MEDIA_STACK_SEED_PRIVATE='.($mode === 'shared-network' ? '0' : '1')."\n"
            .$this->pmssReadRepoFile('etc/skel/bin/mediaStackRadarr.sh')."\n";
        $shell .= <<<'BASH'
log_info() { echo "$*"; }
log_ok() { echo "$*"; }
curl() {
    local url='' output='' config='' proxy=''
    while [[ $# -gt 0 ]]; do
        case "$1" in
        -o) output="$2"; shift 2 ;;
        --config) config="$2"; shift 2 ;;
        --noproxy) proxy="$2"; shift 2 ;;
        --max-time) shift 2 ;;
        -fsS) shift ;;
        *) url="$1"; shift ;;
        esac
    done
    [[ "$url" == http://127.0.0.1:23001/public-fixtur01/radarr/api/v3/rootfolder && "$proxy" == '*' ]] || return 1
    [[ "$config" == "$HOME/curl" && "$FIXTURE_MODE" != read-failure ]] || return 1
    printf '%s' "$ROOT_RESPONSE" >"$output"
    [[ $(stat -c %a "${output%/*}") == 700 && $(stat -c %a "$output") == 600 ]]
}
media_stack_http_code() {
    printf '%s\n' "$*" >>"$HOME/argv"
    local data=''
    while [[ $# -gt 0 ]]; do
        if [[ "$1" == --data-binary ]]; then data="${2#@}"; break; fi
        shift
    done
    [[ $(stat -c %a "$data") == 600 ]] || return 1
    cp "$data" "$HOME/saved"
    printf '%s' "$FIXTURE_CODE"
}
if media_stack_radarr_defaults_seed http://127.0.0.1:23001/public-fixtur01/radarr/api/v3/config/host "$HOME/curl"; then
    echo rc:0
else
    echo rc:$?
fi
BASH;
        $output = $this->pmssRunShellHarness($shell);
        $this->assertSame([], glob($home.'/tmp/*'));
        return [$home, $output];
    }
}
