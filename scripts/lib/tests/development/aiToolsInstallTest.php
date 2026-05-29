<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class AiToolsInstallTest extends TestCase
{
    public function testInstallsAllRequestedCliTools(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('aiToolsInstall.php', ['@google/gemini-cli', '@anthropic-ai/claude-code', '/usr/local/bin/codex']);
    }

    public function testPinsNodeAndCodexArtifacts(): void
    {
        $contents = $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('aiToolsInstall.php', ['node-v22.22.1-linux-x64.tar.xz', 'codex-x86_64-unknown-linux-musl.tar.gz']);
        $this->assertMatches('/[0-9a-f]{64}/', $contents);
    }

    public function testSupportsForceRefreshFlag(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('aiToolsInstall.php', ['PMSS_FORCE_AI_TOOLS_REFRESH']);
    }

    public function testKeepsInlineNodeVersionGate(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('aiToolsInstall.php', [
            'preg_match(\'/^v?([0-9]+)/\', $systemVersion, $match)',
            '>= 22',
        ], ['function pmssAiTools'.'NodeMajor(' => 'Node major parsing should stay inline in the only call site']);
    }

    public function testKeepsNpmCliInstallFlowInline(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('aiToolsInstall.php', [
            "dirname(\$nodeBinary).'/npm'",
            'npm not available',
            "putenv('PATH='.dirname(\$nodeBinary)",
        ], ['function pmssAiTools'.'InstallNpmCli(' => 'NPM CLI installation should stay inline in the only installer']);
    }

    public function testPreservesCodexOldKernelFallback(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('aiToolsInstall.php', ['/etc/codex/config.toml', 'danger-full-access']);
    }
}
