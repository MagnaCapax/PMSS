<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';

class ParseSpecTest extends TestCase
{
    public function testParsesGitBranchWithDate(): void
    {
        $parsed = parseSpec('git/main:2025-05-11');
        $this->assertEquals('git', $parsed['type']);
        $this->assertEquals(DEFAULT_REPO, $parsed['repo']);
        $this->assertEquals('main', $parsed['branch']);
        $this->assertEquals('2025-05-11', $parsed['pin']);
    }

    public function testParsesCustomRepoAndBranch(): void
    {
        $spec = 'git/https://example.com/repo.git:beta';
        $parsed = parseSpec($spec);
        $this->assertEquals('git', $parsed['type']);
        $this->assertEquals('https://example.com/repo.git', $parsed['repo']);
        $this->assertEquals('beta', $parsed['branch']);
        $this->assertEquals('', $parsed['pin']);
    }

    public function testParsesReleaseWithTag(): void
    {
        $parsed = parseSpec('release:2025-07-12');
        $this->assertEquals('release', $parsed['type']);
        $this->assertEquals('2025-07-12', $parsed['pin']);
    }

    public function testNormaliseAndParseBareBranch(): void
    {
        $normalised = normaliseSpec('dev');
        $this->assertEquals('git/dev', $normalised);
        $parsed = parseSpec($normalised);
        $this->assertEquals('dev', $parsed['branch']);
    }
}
