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
        // A pinned spec needs git history to check out branch@{pin}; the tarball is
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
                "git '.GIT_HTTP_VERSION_FLAG.' fetch",
            ],
            'update.php must force HTTP/1.1 on the git clone and the pin-path fetch (git-upload-pack over HTTP/2 is challenged): '
        );
    }
}
