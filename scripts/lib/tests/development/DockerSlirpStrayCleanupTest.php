<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

putenv('PMSS_DOCKER_NO_ENTRYPOINT=1');
require_once dirname(__DIR__, 2).'/update/apps/docker.php';

/** Behaviour of the stray pmss-slirp4netns-XXXXXX clean-up and its pinned-helper gate (#1120). */
class DockerSlirpStrayCleanupTest extends TestCase
{
    /** @return array{0:string,1:array<string,string>} temp dir and fixture paths */
    private function pmssSlirpStrayFixture(): array
    {
        $dir = $this->pmssMakeTempDir('pmss-slirp-stray-');
        file_put_contents($dir.'/target', 'helper');
        $paths = [
            'stray' => $dir.'/pmss-slirp4netns-AbC123',
            'link' => $dir.'/pmss-slirp4netns-Link00',
            'helper' => $dir.'/slirp4netns',
            'dryRunName' => $dir.'/pmss-slirp4netns-dry-run',
            'sevenChars' => $dir.'/pmss-slirp4netns-AbC1234',
            'fiveChars' => $dir.'/pmss-slirp4netns-AbC12',
        ];
        foreach ($paths as $name => $path) {
            if ($name !== 'link') {
                file_put_contents($path, 'binary');
            }
        }
        $this->assertTrue(symlink($dir.'/target', $paths['link']), 'Expected symlink fixture');
        return [$dir, $paths];
    }

    public function testRegularStrayIsRemovedAndEverythingElseKept(): void
    {
        [$dir, $paths] = $this->pmssSlirpStrayFixture();
        \pmssDockerRemoveStraySlirpCopies($dir, false);

        $this->assertFalse(file_exists($paths['stray']), 'Regular stray copy should be removed');
        $this->assertTrue(is_link($paths['link']), 'Symlink with a matching name must be kept');
        foreach (['helper', 'dryRunName', 'sevenChars', 'fiveChars'] as $kept) {
            $this->assertTrue(is_file($paths[$kept]), $kept.' must be kept');
        }
        $this->assertTrue(is_file($dir.'/target'), 'Symlink target must be kept');
    }

    public function testDryRunKeepsEverything(): void
    {
        [, $paths] = $this->pmssSlirpStrayFixture();
        \pmssDockerRemoveStraySlirpCopies(dirname($paths['stray']), true);

        $this->assertTrue(is_file($paths['stray']), 'Dry run must keep the stray copy');
        $this->assertTrue(is_link($paths['link']), 'Dry run must keep the symlink');
    }

    public function testHelperGateNeedsRegularFileWithPinnedChecksum(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-slirp-gate-');
        $helper = $dir.'/slirp4netns';
        $link = $dir.'/slirp4netns-link';
        file_put_contents($helper, 'pinned build');
        symlink($helper, $link);
        $sha = hash('sha256', 'pinned build');

        $this->assertTrue(\pmssDockerSlirpHelperMatchesPin($helper, $sha));
        $this->assertTrue(\pmssDockerSlirpHelperMatchesPin($helper, strtoupper($sha)));
        $this->assertFalse(\pmssDockerSlirpHelperMatchesPin($helper, hash('sha256', 'other build')), 'Mismatched checksum');
        $this->assertFalse(\pmssDockerSlirpHelperMatchesPin($helper, ''), 'Unpinned architecture');
        $this->assertFalse(\pmssDockerSlirpHelperMatchesPin($link, $sha), 'Symlinked helper');
        $this->assertFalse(\pmssDockerSlirpHelperMatchesPin($dir.'/missing', $sha), 'Missing helper');
    }
}
