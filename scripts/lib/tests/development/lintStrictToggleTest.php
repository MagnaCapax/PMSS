<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class LintStrictToggleTest extends TestCase
{
    private function readFile(string $relativePath): string
    {
        return $this->pmssReadRepoFile($relativePath);
    }

    public function testSharpLintUsesEnvironmentOverride(): void
    {
        $contents = $this->readFile('scripts/testing/test-all.sh');

        $this->assertStringContainsString('PMSS_LINT_SHARP_STRICT="${PMSS_LINT_SHARP_STRICT:-0}"', $contents);
        $this->assertStringContainsString('sharp-edges-lint.sh', $contents);
    }

    public function testNetLintUsesEnvironmentOverride(): void
    {
        $contents = $this->readFile('scripts/testing/test-all.sh');

        $this->assertStringContainsString('PMSS_LINT_NET_STRICT="${PMSS_LINT_NET_STRICT:-0}"', $contents);
        $this->assertStringContainsString('net-edges-lint.sh', $contents);
    }

    public function testStatusMessagesMentionStrictModeToggle(): void
    {
        $contents = $this->readFile('scripts/testing/test-all.sh');

        $this->assertStringContainsString('advisory unless strict mode enabled', $contents);
    }
}
