<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class AcdCliInstallerPinTest extends TestCase
{
    private function loadInstaller(): string
    {
        $path = dirname(__DIR__, 2).'/update/apps/acdcli.php';
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    public function testDefinesPinnedCommit(): void
    {
        $contents = $this->loadInstaller();
        $this->assertStringContainsString('$acdCliPinnedCommit', $contents);
        $this->assertMatches('/\\$acdCliPinnedCommit\\s*=\\s*\\x27[0-9a-f]{40}\\x27;/', $contents);
    }

    public function testDefinesPinnedTag(): void
    {
        $contents = $this->loadInstaller();
        $this->assertStringContainsString('$acdCliPinnedTag', $contents);
        $this->assertMatches('/\\$acdCliPinnedTag\\s*=\\s*\\x27[0-9]+\\.[0-9]+\\.[0-9a-z]+\\x27;/', $contents);
    }

    public function testPipInstallUsesPinnedGitRef(): void
    {
        $contents = $this->loadInstaller();
        $this->assertStringContainsString('git+https://github.com/yadayada/acd_cli.git@', $contents);
    }

    public function testUpgradeStillGatedByForceFlag(): void
    {
        $contents = $this->loadInstaller();
        $this->assertStringContainsString("getenv('PMSS_FORCE_ACDCLI_UPDATE')", $contents);
        $this->assertStringContainsString('-m pip show', $contents);
        $this->assertStringContainsString("escapeshellarg('acdcli')", $contents);
        $this->assertStringContainsString('already installed; set PMSS_FORCE_ACDCLI_UPDATE=1', $contents);
    }

    public function testNoUnpinnedGitHeadInstallRemains(): void
    {
        $contents = $this->loadInstaller();
        $this->assertTrue(strpos($contents, 'git+https://github.com/yadayada/acd_cli.git\'') === false, 'Found unpinned git URL usage');
        $this->assertTrue(strpos($contents, 'git+https://github.com/yadayada/acd_cli.git ') === false, 'Found unpinned git URL usage');
    }

    public function testInstallerLetsPythonVenvBootstrapRuntimeHelpers(): void
    {
        $contents = $this->loadInstaller();

        $this->assertStringContainsString("require_once __DIR__.'/pythonVenv.php';", $contents);
        $this->assertTrue(strpos($contents, "require_once __DIR__.'/../runtime/commands.php';") === false, 'acd_cli installer should rely on pythonVenv.php for runtime helper bootstrap');
        $this->assertTrue(strpos($contents, "require_once __DIR__.'/../logging.php';") === false, 'acd_cli installer should not duplicate pythonVenv.php logging bootstrap');
    }
}
