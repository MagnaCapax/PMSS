<?php
namespace PMSS\Tests;

/**
 * Guardrail to stop runtime metadata from sneaking into the config tree.
 */
class ConfigRuntimeGuardrailTest extends TestCase
{
    public function testAppVersionsDirectoryNotTracked(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $path     = $repoRoot.'/etc/seedbox/config/app-versions';
        $entries  = [];
        if (is_dir($path)) {
            $entries = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
        }

        $this->assertTrue(
            !is_dir($path) || count($entries) === 0,
            'Runtime app-version junk does not belong in the repo; read AGENTS.md, the constitution/guardrails, and justify any config additions before shipping them.'
        );
    }
}
