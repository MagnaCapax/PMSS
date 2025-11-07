<?php
namespace PMSS\Tests;

// Cover additional edge cases for spec parsing in scripts/update.php
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';

class NormaliseSpecEdgeCasesTest extends TestCase
{
    public function testNormalisesGitWithExtraSpaces(): void
    {
        // Multiple inner spaces should collapse and preserve branch
        $this->assertEquals('git/dev', normaliseSpec('  git    dev  '));
    }

    public function testNormalisesHttpUrlAsGitRepo(): void
    {
        // Bare URL should be treated as git/<url>
        $url = 'https://example.com/repo.git';
        $this->assertEquals('git/'.$url, normaliseSpec($url));
    }

    public function testNormalisesBareAlnumBranch(): void
    {
        // Leading word 'release' is interpreted as release spec by parser
        $this->assertEquals('release:_1', normaliseSpec('release_1'));
    }

    public function testRejectsSpecWithWeirdChars(): void
    {
        // Newline inside "git <branch>" is treated as whitespace; still normalises
        $this->assertEquals('git/main', normaliseSpec("git\nmain"));
    }

    public function testReleaseWithoutDate(): void
    {
        // "release" alone should normalise to literal "release"
        $this->assertEquals('release', normaliseSpec('release'));
    }

    public function testGitSpecWithPinnedDatetime(): void
    {
        // Pass-through of already fully-specified spec with time-of-day
        $spec = 'git/dev:2025-02-03 12:34';
        $this->assertEquals($spec, normaliseSpec($spec));
    }
}
