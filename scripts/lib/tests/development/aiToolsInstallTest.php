<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class AiToolsInstallTest extends TestCase
{
    private function loadInstaller(): string
    {
        $path = dirname(__DIR__, 2).'/update/apps/aiToolsInstall.php';
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    public function testInstallsAllRequestedCliTools(): void
    {
        $contents = $this->loadInstaller();
        $this->assertStringContainsString('@google/gemini-cli', $contents);
        $this->assertStringContainsString('@anthropic-ai/claude-code', $contents);
        $this->assertStringContainsString('/usr/local/bin/codex', $contents);
    }

    public function testPinsNodeAndCodexArtifacts(): void
    {
        $contents = $this->loadInstaller();
        $this->assertStringContainsString('node-v20.20.0-linux-x64.tar.xz', $contents);
        $this->assertStringContainsString('codex-x86_64-unknown-linux-musl.tar.gz', $contents);
        $this->assertMatches('/[0-9a-f]{64}/', $contents);
    }

    public function testSupportsForceRefreshFlag(): void
    {
        $contents = $this->loadInstaller();
        $this->assertStringContainsString('PMSS_FORCE_AI_TOOLS_REFRESH', $contents);
    }

    public function testPreservesCodexOldKernelFallback(): void
    {
        $contents = $this->loadInstaller();
        $this->assertStringContainsString('/etc/codex/config.toml', $contents);
        $this->assertStringContainsString('danger-full-access', $contents);
    }
}

