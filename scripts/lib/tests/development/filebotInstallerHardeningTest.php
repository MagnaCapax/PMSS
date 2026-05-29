<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class FilebotInstallerHardeningTest extends TestCase
{
    public function testFilebotInstallerUsesRemoteDebHelper(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('filebot.php', ["require_once __DIR__.'/remoteBinary.php';", 'pmssInstallPinnedRemoteDebPackage']);
    }

    public function testFilebotInstallerLetsRemoteBinaryBootstrapRuntimeHelpers(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('filebot.php', [], [
            "require_once __DIR__.'/../runtime/commands.php';" => 'FileBot installer should rely on remoteBinary.php for runtime helper bootstrap',
            "require_once __DIR__.'/../logging.php';" => 'FileBot installer should not duplicate remoteBinary.php logging bootstrap',
        ]);
    }

    public function testFilebotInstallerKeepsHttpsPinnedUrl(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('filebot.php', ['https://pulsedmedia.com/remote/pkg/FileBot_4.9.4_amd64.deb'], ['http://pulsedmedia.com/remote/pkg/' => 'Found insecure FileBot URL']);
    }

    public function testFilebotInstallerPinsChecksumLiteral(): void
    {
        $contents = $this->pmssReadUpdateAppFile('filebot.php');

        $this->assertMatches('/\\x27[0-9a-f]{64}\\x27/', $contents);
    }

    public function testFilebotInstallerKeepsVersionProbeAndPathGuard(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('filebot.php', ['/usr/bin/filebot', 'pmssAppVersionProbeOutput', ' -version 2>/dev/null', '@unlink($filebotPath)']);
    }

    public function testFilebotInstallerNoLongerBuildsDpkgCommandInline(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('filebot.php', [], [
            "pmssBuildCommand('dpkg'" => 'FileBot installer should delegate dpkg invocation to remoteBinary helper',
            "runStep(\"Downloading" => 'FileBot installer should delegate download step to remoteBinary helper',
        ]);
    }

    public function testRemoteBinaryDefinesPinnedDebInstaller(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('remoteBinary.php', [
            'function pmssInstallPinnedRemoteDebPackage',
            'function pmssDownloadPinnedRemoteTempFile',
            "pmssBuildCommand('dpkg'",
            'checksum mismatch; refusing install',
        ]);
    }

    public function testRemoteBinaryKeepsTempCleanupInlineWithFinally(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('remoteBinary.php', [
            'pmssDownloadPinnedRemoteTempFile(',
            "'pmss-remote-bin-'",
            "'pmss-remote-deb-'",
            'try {',
            '} finally {',
        ], ['function pmssRemoteBinary' => 'remoteBinary.php should keep temp-file cleanup inline rather than adding a helper wrapper']);
    }
}
