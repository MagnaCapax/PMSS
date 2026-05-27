<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class FilebotInstallerHardeningTest extends TestCase
{
    public function testFilebotInstallerUsesRemoteDebHelper(): void
    {
        $contents = $this->pmssReadUpdateAppFile('filebot.php');

        $this->assertStringContainsString("require_once __DIR__.'/remoteBinary.php';", $contents);
        $this->assertStringContainsString('pmssInstallPinnedRemoteDebPackage', $contents);
    }

    public function testFilebotInstallerLetsRemoteBinaryBootstrapRuntimeHelpers(): void
    {
        $contents = $this->pmssReadUpdateAppFile('filebot.php');

        $this->assertTrue(strpos($contents, "require_once __DIR__.'/../runtime/commands.php';") === false, 'FileBot installer should rely on remoteBinary.php for runtime helper bootstrap');
        $this->assertTrue(strpos($contents, "require_once __DIR__.'/../logging.php';") === false, 'FileBot installer should not duplicate remoteBinary.php logging bootstrap');
    }

    public function testFilebotInstallerKeepsHttpsPinnedUrl(): void
    {
        $contents = $this->pmssReadUpdateAppFile('filebot.php');

        $this->assertStringContainsString('https://pulsedmedia.com/remote/pkg/FileBot_4.9.4_amd64.deb', $contents);
        $this->assertTrue(strpos($contents, 'http://pulsedmedia.com/remote/pkg/') === false, 'Found insecure FileBot URL');
    }

    public function testFilebotInstallerPinsChecksumLiteral(): void
    {
        $contents = $this->pmssReadUpdateAppFile('filebot.php');

        $this->assertMatches('/\\x27[0-9a-f]{64}\\x27/', $contents);
    }

    public function testFilebotInstallerKeepsVersionProbeAndPathGuard(): void
    {
        $contents = $this->pmssReadUpdateAppFile('filebot.php');

        $this->assertStringContainsAllStrings(['/usr/bin/filebot', 'pmssAppVersionProbeOutput', ' -version 2>/dev/null', '@unlink($filebotPath)'], $contents);
    }

    public function testFilebotInstallerNoLongerBuildsDpkgCommandInline(): void
    {
        $contents = $this->pmssReadUpdateAppFile('filebot.php');

        $this->assertTrue(strpos($contents, "pmssBuildCommand('dpkg'") === false, 'FileBot installer should delegate dpkg invocation to remoteBinary helper');
        $this->assertTrue(strpos($contents, "runStep(\"Downloading") === false, 'FileBot installer should delegate download step to remoteBinary helper');
    }

    public function testRemoteBinaryDefinesPinnedDebInstaller(): void
    {
        $contents = $this->pmssReadUpdateAppFile('remoteBinary.php');

        $this->assertStringContainsAllStrings(['function pmssInstallPinnedRemoteDebPackage', 'function pmssDownloadPinnedRemoteTempFile', "pmssBuildCommand('dpkg'", 'checksum mismatch; refusing install'], $contents);
    }

    public function testRemoteBinaryKeepsTempCleanupInlineWithFinally(): void
    {
        $contents = $this->pmssReadUpdateAppFile('remoteBinary.php');

        $this->assertStringContainsAllStrings(['pmssDownloadPinnedRemoteTempFile(', "'pmss-remote-bin-'", "'pmss-remote-deb-'", 'try {', '} finally {'], $contents);
        $this->assertTrue(strpos($contents, 'function pmssRemoteBinary') === false, 'remoteBinary.php should keep temp-file cleanup inline rather than adding a helper wrapper');
    }
}
