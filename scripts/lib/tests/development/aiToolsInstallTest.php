<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class AiToolsInstallTest extends TestCase
{
    public function testInstallerSourceKeepsPinnedToolContract(): void
    {
        $contents = $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('aiToolsInstall.php', [
            '@google/gemini-cli',
            '@anthropic-ai/claude-code',
            '/usr/local/bin/codex',
            'node-v22.22.1-linux-x64.tar.xz',
            'codex-x86_64-unknown-linux-musl.tar.gz',
            'PMSS_FORCE_AI_TOOLS_REFRESH',
            "preg_match('/^v?([0-9]+)/', \$systemVersion, \$match)",
            '>= 22',
            "dirname(\$nodeBinary).'/npm'",
            'npm not available',
            "putenv('PATH='.dirname(\$nodeBinary)",
            '/etc/codex/config.toml',
            'danger-full-access',
        ], [
            'function pmssAiTools'.'NodeMajor(' => 'Node major parsing should stay inline in the only call site',
            'function pmssAiTools'.'InstallNpmCli(' => 'NPM CLI installation should stay inline in the only installer',
        ]);
        $this->assertMatches('/[0-9a-f]{64}/', $contents);
    }
}
