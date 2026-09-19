<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * fetchSnapshot() must fall back to the codeload branch tarball when `git clone`
 * fails (e.g. a network/edge that blocks the git-upload-pack POST with HTTP 401
 * while plain HTTPS GETs still succeed). codeload is a separate CDN, not the
 * git-upload-pack endpoint. See docs/adr/0050.
 */
class UpdateCodeloadFallbackTest extends TestCase
{
    public function testDatedPinsResolveRealHistoryOrFailBeforeStaging(): void
    {
        $root = $this->pmssMakeTempDir('pmss-dated-pin-');
        $repo = $root.'/source';
        mkdir($repo);
        // Disable inherited config/hooks and use only local file transport.
        $environment = [
            'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_ALLOW_PROTOCOL' => 'file', 'TZ' => 'UTC',
        ];
        $this->pmssWithEnv($environment, function () use ($root, $repo): void {
            $git = 'git -C '.escapeshellarg($repo).' ';
            $this->assertSame(0, $this->pmssExecShellCommand($git.'init --quiet')['rc']);
            $this->assertSame(0, $this->pmssExecShellCommand($git.'symbolic-ref HEAD refs/heads/main')['rc']);
            $commits = [];
            foreach (['2020-01-01 12:00:00', '2020-01-03 12:00:00', '2020-01-05 12:00:00'] as $date) {
                $result = $this->pmssExecShellCommand(
                    $git.'-c user.name=Fixture -c user.email=fixture@example.invalid'
                    .' -c commit.gpgsign=false commit --quiet --allow-empty -m fixture',
                    ['GIT_AUTHOR_DATE' => $date.' +0000', 'GIT_COMMITTER_DATE' => $date.' +0000']
                );
                $this->assertSame(0, $result['rc'], $result['output']);
                $commits[] = trim($this->pmssRunShellCommand($git.'rev-parse HEAD'));
            }

            // Load the real bootstrap with logs redirected inside the fixture.
            $source = file_get_contents($this->pmssRepoPath('scripts/update.php'));
            $source = preg_replace('/^#![^\n]*\n/', '', $source);
            $source = str_replace("const JSON_LOG              = '/var/log/pmss-update.jsonl';",
                'const JSON_LOG = '.var_export($root.'/events.jsonl', true).';', $source);
            file_put_contents($root.'/bootstrap.php', $source);
            $script = 'function logmsg(string $message): void { echo $message."\\n"; }'
                .' require '.var_export($root.'/bootstrap.php', true).';'
                .' $spec = ["type" => "git", "repo" => $argv[1], "branch" => "main", "pin" => $argv[2]];'
                .' echo "FETCHED=".fetchSnapshot($spec, $argv[3]);';
            $php = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script);
            $cases = [
                ['file://'.$repo, '2020-01-02', $commits[0]],
                ['file://'.$repo, '2020-01-04', $commits[1]],
                ['file://'.$repo, '2020-01-03', $commits[0]],
                ['file://'.$repo, '2020-01-03 12:00', $commits[1]],
                ['file://'.$repo, '2020-01-06', $commits[2]],
                ['file://'.$repo, '2019-12-31', null],
                [$repo, '2020-01-04', $commits[1]],
                ['file://'.$repo, '', $commits[2]],
            ];
            // A shallow source must not produce an apparently successful pin.
            $shallow = $root.'/shallow';
            $result = $this->pmssExecShellCommand('git clone --quiet --depth=1 '
                .escapeshellarg('file://'.$repo).' '.escapeshellarg($shallow));
            $this->assertSame(0, $result['rc'], $result['output']);
            $cases[] = ['file://'.$shallow, '2020-01-06', null];
            foreach ($cases as $index => [$remote, $pin, $expected]) {
                $clone = $root.'/clone '.$index;
                $result = $this->pmssExecShellCommand($php.' '.escapeshellarg($remote)
                    .' '.escapeshellarg($pin).' '.escapeshellarg($clone));
                $this->assertSame($expected === null ? 12 : 0, $result['rc'], $result['output']);
                if ($expected === null) {
                    $this->pmssAssertStringNotContainsString('FETCHED=', $result['output']);
                    $this->assertStringContainsString($index === 5 ? 'No git commit' : 'incomplete git history', $result['output']);
                    continue;
                }
                $actual = trim($this->pmssRunShellCommand('git -C '.escapeshellarg($clone).' rev-parse HEAD'));
                $this->assertSame($expected, $actual, $result['output']);
                $this->assertSame($pin === '', is_file($clone.'/.git/shallow'));
                if ($pin !== '') {
                    $this->assertStringContainsString('--detach '.$expected, str_replace("'", '', $result['output']));
                }
            }
        });
    }

    public function testGitCloneFailureFallsBackToCodeloadBranchTarball(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/update.php',
            [
                'pmssCodeloadTarballUrl',
                'pmssFetchBranchTarball',
                'https://codeload.github.com/',
                '/tar.gz/refs/heads/',
                'if (pmssRunBootstrapCommand($clone) !== 0) {',
                '--strip-components=1',
            ],
            'update.php must derive a codeload branch-tarball URL and fetch+extract it as the git-clone fallback: '
        );
    }

    public function testCodeloadFallbackUsesHttp11ForKnownCodeloadHttp2Defect(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'scripts/update.php',
            'curl -sfL --http1.1 -A ',
            'codeload fetch should force HTTP/1.1 (codeload has a known intermittent HTTP/2 400 in CI)'
        );
    }

    public function testPinnedSpecStaysFatalOnCloneFailureBecauseTarballHasNoHistory(): void
    {
        // A pinned spec needs git history to resolve a commit cutoff; the tarball is
        // history-less, so a clone failure with a pin set must remain fatal, not fall
        // back to the tarball.
        $this->pmssAssertRepoFileContainsString(
            'scripts/update.php',
            "fatal('git clone failed and a pinned spec requires git history:",
            'a pinned spec must stay fatal on clone failure (tarball has no git history)'
        );
    }

    public function testGitCloneAndPinFetchForceHttp11ToAvoidGitHubHttp2Challenge(): void
    {
        // GitHub's edge 401s the anonymous git-upload-pack POST over HTTP/2 from some
        // source networks (a known curl-HTTP/2 <-> GitHub failure class); HTTP/1.1 is
        // served normally. update.php must force HTTP/1.1 on BOTH the clone and the
        // pin-path fetch (both hit git-upload-pack). Harmless where HTTP/2 would work.
        // See docs/adr/0052.
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/update.php',
            [
                "const GIT_HTTP_VERSION_FLAG = '-c http.version=HTTP/1.1';",
                "'git %s clone --quiet",
                'GIT_HTTP_VERSION_FLAG,',
                "GIT_HTTP_VERSION_FLAG.' fetch --quiet --unshallow'",
            ],
            'update.php must force HTTP/1.1 on the git clone and the pin-path fetch (git-upload-pack over HTTP/2 is challenged): '
        );
    }
}
