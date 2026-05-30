<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class installBootstrapSafetyTest extends TestCase
{
    /** @var string */
    private $script;

    protected function setUp(): void
    {
        $path = $this->pmssRepoPath('install.sh');
        $script = file_get_contents($path);
        $this->assertTrue($script !== false, 'Failed to read install.sh');
        $this->script = $script;
    }

    public function testSnapshotCleanupAvoidsWildcardDelete(): void
    {
        $broadDeletePattern = 'rm -rf PMSS'.'*';
        $this->assertStringContainsString('cleanup_snapshot_workspace()', $this->script);
        $this->assertStringContainsString('/tmp/PMSS.tar.gz', $this->script);
        $this->assertStringNotContainsString($broadDeletePattern, $this->script);
    }

    public function testSnapshotTreeValidatedBeforeStaging(): void
    {
        $this->assertOrderedStrings([
            'validate_snapshot_tree PMSS || exit 1',
            'run_required rsync -a --ignore-missing-args PMSS/{var,scripts,etc} /',
        ], $this->script);
    }

    public function testCriticalSnapshotCommandsUseRequiredRunner(): void
    {
        $this->assertStringContainsAllStrings([
            'run_required git clone "$repository" PMSS',
            'run_required wget "https://api.github.com/repos/MagnaCapax/PMSS/tarball/${VERSION}" -O PMSS.tar.gz',
            'run_required tar -xzf PMSS.tar.gz -C PMSS --strip-components 1',
        ], $this->script);
    }

    public function testLatestReleaseResolutionCannotFallThroughEmpty(): void
    {
        $this->assertStringContainsString('Unable to resolve latest PMSS release tag', $this->script);
    }
}
