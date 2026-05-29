<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class PhpstanUpdateAdvisoryTest extends TestCase
{
    public function testAdvisoryScriptUsesScopedConfig(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/testing/phpstan-update-advisory.sh', ['phpstan.update.neon.dist', 'scripts/lib/update']);
    }

    public function testAdvisoryScriptUsesSharedPhpstanRunner(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/testing/phpstan-update-advisory.sh', ['scripts/testing/phpstan.sh', 'ALLOW_TOOL_SKIP']);
    }

    public function testAdvisoryScriptIsNonBlockingOnFindings(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/testing/phpstan-update-advisory.sh', ['findings detected (non-blocking)', 'exit 0']);
    }

    public function testTestAllSupportsAdvisoryToggle(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/testing/test-all.sh', ['PMSS_LINT_PHPSTAN_UPDATE', 'phpstan-update-advisory.sh']);
    }

    public function testScopedConfigTargetsUpdateLibraries(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('phpstan.update.neon.dist', ['level: 2', '- scripts/lib/update']);
    }
}
