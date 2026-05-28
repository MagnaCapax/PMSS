<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class PhpstanUpdateAdvisoryTest extends TestCase
{
    public function testAdvisoryScriptUsesScopedConfig(): void
    {
        $contents = $this->pmssReadRepoFile('scripts/testing/phpstan-update-advisory.sh');

        $this->assertStringContainsAllStrings(['phpstan.update.neon.dist', 'scripts/lib/update'], $contents);
    }

    public function testAdvisoryScriptUsesSharedPhpstanRunner(): void
    {
        $contents = $this->pmssReadRepoFile('scripts/testing/phpstan-update-advisory.sh');

        $this->assertStringContainsAllStrings(['scripts/testing/phpstan.sh', 'ALLOW_TOOL_SKIP'], $contents);
    }

    public function testAdvisoryScriptIsNonBlockingOnFindings(): void
    {
        $contents = $this->pmssReadRepoFile('scripts/testing/phpstan-update-advisory.sh');

        $this->assertStringContainsAllStrings(['findings detected (non-blocking)', 'exit 0'], $contents);
    }

    public function testTestAllSupportsAdvisoryToggle(): void
    {
        $contents = $this->pmssReadRepoFile('scripts/testing/test-all.sh');

        $this->assertStringContainsAllStrings(['PMSS_LINT_PHPSTAN_UPDATE', 'phpstan-update-advisory.sh'], $contents);
    }

    public function testScopedConfigTargetsUpdateLibraries(): void
    {
        $contents = $this->pmssReadRepoFile('phpstan.update.neon.dist');

        $this->assertStringContainsAllStrings(['level: 2', '- scripts/lib/update'], $contents);
    }
}
