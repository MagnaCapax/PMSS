<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class BtsyncInstallerHardeningTest extends TestCase
{
    public function testBtsyncInstallerKeepsPinnedRemoteBinaryContract(): void
    {
        $contents = $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('btsync.php', [
            "require_once __DIR__.'/remoteBinary.php';",
            'pmssInstallPinnedRemoteBinary',
            'https://pulsedmedia.com/remote/pkg/',
            'sha256',
            "runStep('Linking btsync shim'",
            "pmssInstallPinnedRemoteBinary('Resilio Sync'",
        ], [
            'http://pulsedmedia.com/remote/pkg/' => 'Found insecure http:// remote/pkg URL',
            '--help 2>/dev/null' => 'Found rslsync --help probing',
            'Resilio Sync 2.' => 'Found pinned version string probing',
            "require_once __DIR__.'/../runtime/commands.php';" => 'BTSync installer should rely on remoteBinary.php for runtime helper bootstrap',
            "require_once __DIR__.'/../logging.php';" => 'BTSync installer should not duplicate remoteBinary.php logging bootstrap',
            "@hash_file('sha256', \$rslsyncBinary)" => 'BTSync installer should not duplicate the helper checksum decision for rslsync',
        ]);
        $this->assertMatches('/\\x27[0-9a-f]{64}\\x27/', $contents);
    }
}
