<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class BtsyncInstallerHardeningTest extends TestCase
{
    public function testUsesRemoteBinaryHelper(): void
    {
        $contents = $this->pmssReadUpdateAppFile('btsync.php');
        $this->assertStringContainsString("require_once __DIR__.'/remoteBinary.php';", $contents);
        $this->assertStringContainsString('pmssInstallPinnedRemoteBinary', $contents);
    }

    public function testUsesHttpsOnly(): void
    {
        $contents = $this->pmssReadUpdateAppFile('btsync.php');
        $this->assertTrue(strpos($contents, 'http://pulsedmedia.com/remote/pkg/') === false, 'Found insecure http:// remote/pkg URL');
        $this->assertStringContainsString('https://pulsedmedia.com/remote/pkg/', $contents);
    }

    public function testPinsChecksums(): void
    {
        $contents = $this->pmssReadUpdateAppFile('btsync.php');
        $this->assertMatches('/\\x27[0-9a-f]{64}\\x27/', $contents);
        $this->assertStringContainsString('sha256', $contents);
    }

    public function testNoVersionProbeExecutionRemains(): void
    {
        $contents = $this->pmssReadUpdateAppFile('btsync.php');
        $this->assertTrue(strpos($contents, '--help 2>/dev/null') === false, 'Found rslsync --help probing');
        $this->assertTrue(strpos($contents, 'Resilio Sync 2.') === false, 'Found pinned version string probing');
    }

    public function testRunStepUsedForLinking(): void
    {
        $contents = $this->pmssReadUpdateAppFile('btsync.php');
        $this->assertStringContainsString("runStep('Linking btsync shim'", $contents);
    }

    public function testInstallerLetsRemoteBinaryBootstrapRuntimeHelpers(): void
    {
        $contents = $this->pmssReadUpdateAppFile('btsync.php');

        $this->assertTrue(strpos($contents, "require_once __DIR__.'/../runtime/commands.php';") === false, 'BTSync installer should rely on remoteBinary.php for runtime helper bootstrap');
        $this->assertTrue(strpos($contents, "require_once __DIR__.'/../logging.php';") === false, 'BTSync installer should not duplicate remoteBinary.php logging bootstrap');
    }
}
