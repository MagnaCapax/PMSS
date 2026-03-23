<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/RepoFileReadTrait.php';

class PhpstanUpdateAdvisoryTest extends TestCase
{
    use RepoFileReadTrait;

    public function testAdvisoryScriptUsesScopedConfig(): void
    {
        $contents = $this->readRepoFile('scripts/testing/phpstan-update-advisory.sh');

        $this->assertStringContainsString('phpstan.update.neon.dist', $contents);
        $this->assertStringContainsString('scripts/lib/update', $contents);
    }

    public function testAdvisoryScriptUsesSharedPhpstanRunner(): void
    {
        $contents = $this->readRepoFile('scripts/testing/phpstan-update-advisory.sh');

        $this->assertStringContainsString('scripts/testing/phpstan.sh', $contents);
        $this->assertStringContainsString('ALLOW_TOOL_SKIP', $contents);
    }

    public function testAdvisoryScriptIsNonBlockingOnFindings(): void
    {
        $contents = $this->readRepoFile('scripts/testing/phpstan-update-advisory.sh');

        $this->assertStringContainsString('findings detected (non-blocking)', $contents);
        $this->assertStringContainsString('exit 0', $contents);
    }

    public function testTestAllSupportsAdvisoryToggle(): void
    {
        $contents = $this->readRepoFile('scripts/testing/test-all.sh');

        $this->assertStringContainsString('PMSS_LINT_PHPSTAN_UPDATE', $contents);
        $this->assertStringContainsString('phpstan-update-advisory.sh', $contents);
    }

    public function testScopedConfigTargetsUpdateLibraries(): void
    {
        $contents = $this->readRepoFile('phpstan.update.neon.dist');

        $this->assertStringContainsString('level: 2', $contents);
        $this->assertStringContainsString('- scripts/lib/update', $contents);
    }
}
