<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class BtsyncInstallerHardeningTest extends TestCase
{
    public function testUsesRemoteBinaryHelper(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('btsync.php', ["require_once __DIR__.'/remoteBinary.php';", 'pmssInstallPinnedRemoteBinary']);
    }

    public function testUsesHttpsOnly(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('btsync.php', ['https://pulsedmedia.com/remote/pkg/'], ['http://pulsedmedia.com/remote/pkg/' => 'Found insecure http:// remote/pkg URL']);
    }

    public function testPinsChecksums(): void
    {
        $contents = $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('btsync.php', ['sha256']);
        $this->assertMatches('/\\x27[0-9a-f]{64}\\x27/', $contents);
    }

    public function testNoVersionProbeExecutionRemains(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('btsync.php', [], [
            '--help 2>/dev/null' => 'Found rslsync --help probing',
            'Resilio Sync 2.' => 'Found pinned version string probing',
        ]);
    }

    public function testRunStepUsedForLinking(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('btsync.php', ["runStep('Linking btsync shim'"]);
    }

    public function testInstallerLetsRemoteBinaryBootstrapRuntimeHelpers(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('btsync.php', [], [
            "require_once __DIR__.'/../runtime/commands.php';" => 'BTSync installer should rely on remoteBinary.php for runtime helper bootstrap',
            "require_once __DIR__.'/../logging.php';" => 'BTSync installer should not duplicate remoteBinary.php logging bootstrap',
        ]);
    }

    public function testResilioRefreshDecisionStaysInRemoteBinaryHelper(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('btsync.php', ["pmssInstallPinnedRemoteBinary('Resilio Sync'"], [
            "@hash_file('sha256', \$rslsyncBinary)" => 'BTSync installer should not duplicate the helper checksum decision for rslsync',
        ]);
    }
}
