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
}
