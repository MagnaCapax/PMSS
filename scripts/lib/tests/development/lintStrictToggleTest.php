<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class LintStrictToggleTest extends TestCase
{
    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    public function testSharpLintUsesEnvironmentOverride(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/testing/test-all.sh';
        $contents = $this->readFile($path);

        $this->assertStringContainsString('PMSS_LINT_SHARP_STRICT="${PMSS_LINT_SHARP_STRICT:-0}"', $contents);
        $this->assertStringContainsString('sharp-edges-lint.sh', $contents);
    }

    public function testNetLintUsesEnvironmentOverride(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/testing/test-all.sh';
        $contents = $this->readFile($path);

        $this->assertStringContainsString('PMSS_LINT_NET_STRICT="${PMSS_LINT_NET_STRICT:-0}"', $contents);
        $this->assertStringContainsString('net-edges-lint.sh', $contents);
    }

    public function testStatusMessagesMentionStrictModeToggle(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/testing/test-all.sh';
        $contents = $this->readFile($path);

        $this->assertStringContainsString('advisory unless strict mode enabled', $contents);
    }
}

