<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** The bash check gates CI: shellcheck and shfmt findings must fail it, never only print. */
class TestBashStrictModeTest extends TestCase
{
    /** Non-comment lines of the bash check that invoke the given tool through the shared runner. */
    private function toolLines(string $tool): array
    {
        $lines = [];
        foreach (explode("\n", $this->pmssReadRepoFile('scripts/testing/test-bash.sh')) as $line) {
            if (preg_match('/^\s*[^#\s].*\b'.$tool.'\b/', $line) === 1 && strpos($line, 'command -v') === false) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    public function testShellcheckFindingsFailTheCheck(): void
    {
        $lines = $this->toolLines('shellcheck');
        $this->assertTrue($lines !== [], 'test-bash.sh must invoke shellcheck');
        foreach ($lines as $line) {
            $this->assertFalse(strpos($line, '|| true') !== false, 'shellcheck must not be masked: '.$line);
        }
    }

    public function testShfmtFindingsFailTheCheck(): void
    {
        $lines = $this->toolLines('shfmt');
        $this->assertTrue($lines !== [], 'test-bash.sh must invoke shfmt');
        foreach ($lines as $line) {
            $this->assertFalse(strpos($line, '|| true') !== false, 'shfmt must not be masked: '.$line);
        }
    }

    public function testHeaderDocumentsStrictMode(): void
    {
        $head = implode("\n", array_slice(explode("\n", $this->pmssReadRepoFile('scripts/testing/test-bash.sh')), 0, 12));
        $this->assertStringContainsString('strict mode', strtolower($head));
    }
}
