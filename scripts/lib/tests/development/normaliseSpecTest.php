<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';

class NormaliseSpecTest extends TestCase
{
    public function testNormalisesGitWithSpace(): void
    {
        $this->assertEquals('git/main', normaliseSpec('git main'));
    }

    public function testNormalisesBareBranch(): void
    {
        $this->assertEquals('git/main', normaliseSpec('main'));
    }

    public function testNormalisesReleaseWithSpace(): void
    {
        $this->assertEquals('release:2025-07-12', normaliseSpec('release 2025-07-12'));
    }

    public function testNormalisesReleaseEmptyDelimiterForms(): void
    {
        $this->assertEquals('release', normaliseSpec('release:'));
        $this->assertEquals('release', normaliseSpec('release/'));
    }

    public function testKeepsFullGitSpec(): void
    {
        $this->assertEquals('git/dev:2024-07-01', normaliseSpec('git/dev:2024-07-01'));
    }

    public function testHandlesCustomRepo(): void
    {
        $spec = 'git/https://example.com/repo.git:beta';
        $this->assertEquals($spec, normaliseSpec($spec));
    }

    public function testRejectsInvalidSpec(): void
    {
        $this->assertEquals('', normaliseSpec('@@bad??!!'));
    }

    public function testDefaultSpecFormat(): void
    {
        if (is_file(\VERSION_FILE)) {
            throw new SkipTest('VERSION_FILE exists on this host; default spec depends on stored spec');
        }

        $parsed = \parseArguments(['update.php']);
        $this->assertEquals('git/main', $parsed['spec'] ?? '');
    }
}
