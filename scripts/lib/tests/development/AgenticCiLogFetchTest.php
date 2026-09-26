<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise CI log and run selection with local command stubs only. */
class AgenticCiLogFetchTest extends TestCase
{
    private $script;

    public function setUp(): void
    {
        $this->script = $this->pmssReadRepoFile('development/agentic-ci.sh');
    }

    private function runFixture(string $body, string $cwd, array $environment = []): array
    {
        $harness = $this->pmssMakeTempDir('pmss-ci-harness-').'/run.sh';
        $this->pmssWriteExecutableFile($harness, "#!/usr/bin/env bash\nset -euo pipefail\n".$body."\n");
        $prefix = 'cd '.escapeshellarg($cwd).' && env -u TMP -u TMPDIR';
        foreach ($environment as $key => $value) {
            $prefix .= ' '.escapeshellarg($key.'='.$value);
        }
        return $this->pmssExecShellCommand($prefix.' bash '.escapeshellarg($harness));
    }

    private function zipFixture(string $root): string
    {
        $source = $root.'/source';
        $this->pmssEnsureDir($source);
        $this->pmssWriteFile($source.'/build.txt', "build log\n");
        $zip = $root.'/run.zip';
        $result = $this->pmssExecShellCommand('cd '.escapeshellarg($source).' && zip -q '.escapeshellarg($zip).' build.txt');
        $this->assertSame(0, $result['rc'], $result['output']);
        return $zip;
    }

    public function testRunZipUsesSafeDefaultTempDirectory(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ci-zip-');
        $cwd = $root.'/cwd';
        $this->pmssEnsureDir($cwd);
        $zip = $this->zipFixture($root);
        $out = $root.'/out.log';
        $body = $this->pmssExtractShellFunctions($this->script, ['ci_unzip_to_file'])."\n".
            'ci_unzip_to_file '.escapeshellarg($zip).' '.escapeshellarg($out);
        $result = $this->runFixture($body, $cwd);
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertStringContainsString('build log', (string) file_get_contents($out));
        $this->assertSame([], scandir($cwd) ? array_values(array_diff(scandir($cwd), ['.', '..'])) : []);
    }

    public function testRunZipFailsWithoutExtractingWhenTempDirectoryCannotBeCreated(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ci-zip-fail-');
        $cwd = $root.'/cwd';
        $this->pmssEnsureDir($cwd);
        $zip = $this->zipFixture($root);
        $blocked = $root.'/not-a-directory';
        $this->pmssWriteFile($blocked, 'blocked');
        $out = $root.'/out.log';
        $body = $this->pmssExtractShellFunctions($this->script, ['ci_unzip_to_file'])."\n".
            'ci_unzip_to_file '.escapeshellarg($zip).' '.escapeshellarg($out);
        $result = $this->runFixture($body, $cwd, ['TMPDIR' => $blocked]);
        $this->assertTrue($result['rc'] !== 0);
        $this->assertFalse(file_exists($out));
        $this->assertSame([], array_values(array_diff(scandir($cwd), ['.', '..'])));
    }

    public function testEmptyGhJobViewFallsBackToRestText(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ci-gh-');
        $bin = $root.'/bin';
        $this->pmssEnsureDir($bin);
        $this->pmssWriteExecutableFile($bin.'/gh', <<<'BASH'
#!/usr/bin/env bash
if [[ "$1 $2" == "run view" ]]; then
    [[ " $* " == *" --json jobs "* ]] && printf '42\n'
    exit 0
fi
if [[ "$1" == api && "$*" == *'/jobs/42/logs'* ]]; then
    printf 'REST job log\n'
    exit 0
fi
[[ "$1 $2" == "auth token" ]] && printf 'fixture-token\n'
BASH
        );
        $out = $root.'/job.log';
        $body = 'export PATH='.escapeshellarg($bin).':$PATH' . "\n".
            'fetch_mode=gh; run_id=7; repo_full=MagnaCapax/PMSS; SUMMARY=/dev/null; OUTDIR='.escapeshellarg($root)."\n".
            'ci_api_download_zip() { return 1; }' . "\n".
            $this->pmssExtractShellFunctions($this->script, ['fetch_job_log'])."\n".
            'fetch_job_log build '.escapeshellarg($out);
        $result = $this->runFixture($body, $root);
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame("REST job log\n", file_get_contents($out));
    }

    public function testCurlJobLogIsPlainText(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ci-curl-');
        $bin = $root.'/bin';
        $this->pmssEnsureDir($bin);
        $this->pmssWriteExecutableFile($bin.'/curl', <<<'BASH'
#!/usr/bin/env bash
for arg in "$@"; do url="$arg"; done
case "$url" in
    */jobs/42/logs) printf 'REST curl log\n';;
    *) printf '{}';;
esac
BASH
        );
        $out = $root.'/job.log';
        $body = 'export PATH='.escapeshellarg($bin).':$PATH' . "\n".
            'fetch_mode=curl; run_id=7; repo_full=MagnaCapax/PMSS; OUTDIR='.escapeshellarg($root)."\n".
            'api_base=https://api.github.test' . "\n".
            'ci_shell_disable_xtrace() { :; }; ci_shell_restore_xtrace() { :; }' . "\n".
            'codex_json_filter_stdin() { cat >/dev/null; printf "42"; }' . "\n".
            'ci_api_download_zip() { return 1; }' . "\n".
            $this->pmssExtractShellFunctions($this->script, ['ci_api_get_json', 'ci_job_id_from_jobs_json', 'fetch_job_log'])."\n".
            'fetch_job_log build '.escapeshellarg($out);
        $result = $this->runFixture($body, $root);
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame("REST curl log\n", file_get_contents($out));
    }

    public function testBothGhRunListsSelectCurrentPushBranch(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ci-branch-');
        $bin = $root.'/bin';
        $this->pmssEnsureDir($bin);
        $trace = $root.'/args';
        $this->pmssWriteExecutableFile($bin.'/gh', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$TRACE"
[[ "$*" == *databaseId* ]] && printf '7\n' || printf 'failure\n'
BASH
        );
        $this->pmssWriteExecutableFile($bin.'/git', <<<'BASH'
#!/usr/bin/env bash
[[ "$*" == *'rev-parse'* ]] && printf 'main\n' || printf 'https://github.com/MagnaCapax/PMSS\n'
BASH
        );
        $preflight = $this->scriptPart('# Pre-flight: skip session', "\nhave() {");
        $discovery = $this->scriptPart("run_id=\"\"\nrepo_full=", "\nif [[ -z \"\$run_id\" ]];");
        $branch = $this->scriptPart('ci_branch=', "\n# Pre-flight: skip session");
        $body = 'export PATH='.escapeshellarg($bin).':$PATH; export TRACE='.escapeshellarg($trace)."\n".
            'autocommit=1; dry_run=0; fetch_mode=gh' . "\n".
            'ci_parse_github_repo() { printf "MagnaCapax/PMSS"; }' . "\n".
            'ci_validate_github_repo() { return 0; }' . "\n".
            $branch."\n".$preflight."\n".$discovery;
        $result = $this->runFixture($body, $root);
        $this->assertSame(0, $result['rc'], $result['output']);
        $calls = file($trace, FILE_IGNORE_NEW_LINES);
        $this->assertSame(2, count($calls));
        foreach ($calls as $call) {
            $this->assertStringContainsString('--branch main --event push', $call);
        }
    }

    public function testCurlRunDiscoveryEncodesBranchAndSelectsPush(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ci-curl-branch-');
        $bin = $root.'/bin';
        $this->pmssEnsureDir($bin);
        $trace = $root.'/urls';
        $this->pmssWriteExecutableFile($bin.'/git', <<<'BASH'
#!/usr/bin/env bash
[[ "$*" == *'rev-parse'* ]] && printf 'fix/ci logs\n' || printf 'https://github.com/MagnaCapax/PMSS\n'
BASH
        );
        $this->pmssWriteExecutableFile($bin.'/curl', <<<'BASH'
#!/usr/bin/env bash
for arg in "$@"; do url="$arg"; done
printf '%s\n' "$url" >> "$TRACE"
printf '{"workflow_runs":[{"id":7}]}'
BASH
        );
        $branch = $this->scriptPart('ci_branch=', "\n# Pre-flight: skip session");
        $discovery = $this->scriptPart("run_id=\"\"\nrepo_full=", "\nif [[ -z \"\$run_id\" ]];");
        $body = 'export PATH='.escapeshellarg($bin).':$PATH; export TRACE='.escapeshellarg($trace)."\n".
            'fetch_mode=curl' . "\n".
            'ci_parse_github_repo() { printf "MagnaCapax/PMSS"; }' . "\n".
            'ci_validate_github_repo() { return 0; }' . "\n".
            'ci_shell_disable_xtrace() { :; }; ci_shell_restore_xtrace() { :; }' . "\n".
            'codex_json_filter_stdin() { cat >/dev/null; printf "7"; }' . "\n".
            $this->pmssExtractShellFunctions($this->script, ['ci_api_get_json'])."\n".
            $branch."\n".$discovery;
        $result = $this->runFixture($body, $root);
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertStringContainsString('branch=fix%2Fci%20logs&event=push', (string) file_get_contents($trace));
    }

    private function scriptPart(string $start, string $end): string
    {
        $from = strpos($this->script, $start);
        $this->assertTrue($from !== false, 'Missing script start '.$start);
        $to = strpos($this->script, $end, $from);
        $this->assertTrue($to !== false, 'Missing script end '.$end);
        return substr($this->script, $from, $to - $from);
    }
}
