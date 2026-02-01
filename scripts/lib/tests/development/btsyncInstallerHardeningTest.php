<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class BtsyncInstallerHardeningTest extends TestCase
{
    private function loadInstaller(): string
    {
        $path = dirname(__DIR__, 2).'/update/apps/btsync.php';
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    public function testUsesRemoteBinaryHelper(): void
    {
        $contents = $this->loadInstaller();
        $this->assertStringContainsString("require_once __DIR__.'/remoteBinary.php';", $contents);
        $this->assertStringContainsString('pmssInstallPinnedRemoteBinary', $contents);
    }

    public function testUsesHttpsOnly(): void
    {
        $contents = $this->loadInstaller();
        $this->assertTrue(strpos($contents, 'http://pulsedmedia.com/remote/pkg/') === false, 'Found insecure http:// remote/pkg URL');
        $this->assertStringContainsString('https://pulsedmedia.com/remote/pkg/', $contents);
    }

    public function testPinsChecksums(): void
    {
        $contents = $this->loadInstaller();
        $this->assertMatches('/\\x27[0-9a-f]{64}\\x27/', $contents);
        $this->assertStringContainsString('sha256', $contents);
    }

    public function testNoVersionProbeExecutionRemains(): void
    {
        $contents = $this->loadInstaller();
        $this->assertTrue(strpos($contents, '--help 2>/dev/null') === false, 'Found rslsync --help probing');
        $this->assertTrue(strpos($contents, 'Resilio Sync 2.') === false, 'Found pinned version string probing');
    }

    public function testRunStepUsedForLinking(): void
    {
        $contents = $this->loadInstaller();
        $this->assertStringContainsString("runStep('Linking btsync shim'", $contents);
    }
}

