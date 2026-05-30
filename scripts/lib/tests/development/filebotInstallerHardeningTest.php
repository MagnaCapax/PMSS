<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class FilebotInstallerHardeningTest extends TestCase
{
    public function testFilebotInstallerKeepsPinnedRemoteDebContract(): void
    {
        $contents = $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('filebot.php', [
            "require_once __DIR__.'/remoteBinary.php';",
            'pmssInstallPinnedRemoteDebPackage',
            'https://pulsedmedia.com/remote/pkg/FileBot_4.9.4_amd64.deb',
            '/usr/bin/filebot',
            'pmssAppVersionProbeOutput',
            ' -version 2>/dev/null',
            '@unlink($filebotPath)',
        ], [
            "require_once __DIR__.'/../runtime/commands.php';" => 'FileBot installer should rely on remoteBinary.php for runtime helper bootstrap',
            "require_once __DIR__.'/../logging.php';" => 'FileBot installer should not duplicate remoteBinary.php logging bootstrap',
            'http://pulsedmedia.com/remote/pkg/' => 'Found insecure FileBot URL',
            "pmssBuildCommand('dpkg'" => 'FileBot installer should delegate dpkg invocation to remoteBinary helper',
            "runStep(\"Downloading" => 'FileBot installer should delegate download step to remoteBinary helper',
        ]);
        $this->assertMatches('/\\x27[0-9a-f]{64}\\x27/', $contents);
    }

    public function testRemoteBinaryKeepsPinnedDebInstallerAndInlineCleanup(): void
    {
        $this->pmssAssertUpdateAppFileContainsAndOmitsStrings('remoteBinary.php', [
            'function pmssInstallPinnedRemoteDebPackage',
            'function pmssDownloadPinnedRemoteTempFile',
            "pmssBuildCommand('dpkg'",
            'checksum mismatch; refusing install',
            'pmssDownloadPinnedRemoteTempFile(',
            "'pmss-remote-bin-'",
            "'pmss-remote-deb-'",
            'try {',
            '} finally {',
        ], ['function pmssRemoteBinary' => 'remoteBinary.php should keep temp-file cleanup inline rather than adding a helper wrapper']);
    }
}
