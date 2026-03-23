<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class LintStrictToggleTest extends TestCase
{
    public function testSharpLintUsesEnvironmentOverride(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/testing/test-all.sh',
            ['PMSS_LINT_SHARP_STRICT="${PMSS_LINT_SHARP_STRICT:-0}"', 'sharp-edges-lint.sh']
        );
    }

    public function testNetLintUsesEnvironmentOverride(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/testing/test-all.sh',
            ['PMSS_LINT_NET_STRICT="${PMSS_LINT_NET_STRICT:-0}"', 'net-edges-lint.sh']
        );
    }

    public function testStatusMessagesMentionStrictModeToggle(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/testing/test-all.sh', 'advisory unless strict mode enabled');
    }
}
