<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class PhpstanUpdateAdvisoryTest extends TestCase
{
    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    public function testAdvisoryScriptUsesScopedConfig(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/testing/phpstan-update-advisory.sh';
        $contents = $this->readFile($path);

        $this->assertStringContainsString('phpstan.update.neon.dist', $contents);
        $this->assertStringContainsString('scripts/lib/update', $contents);
    }

    public function testAdvisoryScriptUsesSharedPhpstanRunner(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/testing/phpstan-update-advisory.sh';
        $contents = $this->readFile($path);

        $this->assertStringContainsString('scripts/testing/phpstan.sh', $contents);
        $this->assertStringContainsString('ALLOW_TOOL_SKIP', $contents);
    }

    public function testAdvisoryScriptIsNonBlockingOnFindings(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/testing/phpstan-update-advisory.sh';
        $contents = $this->readFile($path);

        $this->assertStringContainsString('findings detected (non-blocking)', $contents);
        $this->assertStringContainsString('exit 0', $contents);
    }

    public function testTestAllSupportsAdvisoryToggle(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/testing/test-all.sh';
        $contents = $this->readFile($path);

        $this->assertStringContainsString('PMSS_LINT_PHPSTAN_UPDATE', $contents);
        $this->assertStringContainsString('phpstan-update-advisory.sh', $contents);
    }

    public function testScopedConfigTargetsUpdateLibraries(): void
    {
        $path = dirname(__DIR__, 4).'/phpstan.update.neon.dist';
        $contents = $this->readFile($path);

        $this->assertStringContainsString('level: 2', $contents);
        $this->assertStringContainsString('- scripts/lib/update', $contents);
    }
}

