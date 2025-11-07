<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';

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
        $this->assertEquals('git/main', defaultSpec());
    }
}
