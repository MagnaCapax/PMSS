<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class AiToolsInstallTest extends TestCase
{
    public function testInstallsAllRequestedCliTools(): void
    {
        $contents = $this->pmssReadUpdateAppFile('aiToolsInstall.php');
        $this->assertStringContainsString('@google/gemini-cli', $contents);
        $this->assertStringContainsString('@anthropic-ai/claude-code', $contents);
        $this->assertStringContainsString('/usr/local/bin/codex', $contents);
    }

    public function testPinsNodeAndCodexArtifacts(): void
    {
        $contents = $this->pmssReadUpdateAppFile('aiToolsInstall.php');
        $this->assertStringContainsString('node-v22.22.1-linux-x64.tar.xz', $contents);
        $this->assertStringContainsString('codex-x86_64-unknown-linux-musl.tar.gz', $contents);
        $this->assertMatches('/[0-9a-f]{64}/', $contents);
    }

    public function testSupportsForceRefreshFlag(): void
    {
        $contents = $this->pmssReadUpdateAppFile('aiToolsInstall.php');
        $this->assertStringContainsString('PMSS_FORCE_AI_TOOLS_REFRESH', $contents);
    }

    public function testKeepsInlineNodeVersionGate(): void
    {
        $contents = $this->pmssReadUpdateAppFile('aiToolsInstall.php');
        $this->assertStringContainsString('preg_match(\'/^v?([0-9]+)/\', $systemVersion, $match)', $contents);
        $this->assertStringContainsString('>= 22', $contents);
        $this->assertTrue(strpos($contents, 'function pmssAiTools'.'NodeMajor(') === false, 'Node major parsing should stay inline in the only call site');
    }

    public function testKeepsNpmCliInstallFlowInline(): void
    {
        $contents = $this->pmssReadUpdateAppFile('aiToolsInstall.php');
        $this->assertStringContainsString("dirname(\$nodeBinary).'/npm'", $contents);
        $this->assertStringContainsString('npm not available', $contents);
        $this->assertTrue(
            strpos($contents, 'function pmssAiTools'.'InstallNpmCli(') === false,
            'NPM CLI installation should stay inline in the only installer'
        );
    }

    public function testPreservesCodexOldKernelFallback(): void
    {
        $contents = $this->pmssReadUpdateAppFile('aiToolsInstall.php');
        $this->assertStringContainsString('/etc/codex/config.toml', $contents);
        $this->assertStringContainsString('danger-full-access', $contents);
    }
}
