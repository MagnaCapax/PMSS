<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class FilebotInstallerHardeningTest extends TestCase
{
    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    public function testFilebotInstallerUsesRemoteDebHelper(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/filebot.php';
        $contents = $this->readFile($path);

        $this->assertStringContainsString("require_once __DIR__.'/remoteBinary.php';", $contents);
        $this->assertStringContainsString('pmssInstallPinnedRemoteDebPackage', $contents);
    }

    public function testFilebotInstallerLetsRemoteBinaryBootstrapRuntimeHelpers(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/filebot.php';
        $contents = $this->readFile($path);

        $this->assertTrue(strpos($contents, "require_once __DIR__.'/../runtime/commands.php';") === false, 'FileBot installer should rely on remoteBinary.php for runtime helper bootstrap');
        $this->assertTrue(strpos($contents, "require_once __DIR__.'/../logging.php';") === false, 'FileBot installer should not duplicate remoteBinary.php logging bootstrap');
    }

    public function testFilebotInstallerKeepsHttpsPinnedUrl(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/filebot.php';
        $contents = $this->readFile($path);

        $this->assertStringContainsString('https://pulsedmedia.com/remote/pkg/FileBot_4.9.4_amd64.deb', $contents);
        $this->assertTrue(strpos($contents, 'http://pulsedmedia.com/remote/pkg/') === false, 'Found insecure FileBot URL');
    }

    public function testFilebotInstallerPinsChecksumLiteral(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/filebot.php';
        $contents = $this->readFile($path);

        $this->assertMatches('/\\x27[0-9a-f]{64}\\x27/', $contents);
    }

    public function testFilebotInstallerKeepsVersionProbeAndPathGuard(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/filebot.php';
        $contents = $this->readFile($path);

        $this->assertStringContainsString('/usr/bin/filebot', $contents);
        $this->assertStringContainsString('filebot -version 2>/dev/null', $contents);
        $this->assertStringContainsString('@unlink($filebotPath)', $contents);
    }

    public function testFilebotInstallerNoLongerBuildsDpkgCommandInline(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/filebot.php';
        $contents = $this->readFile($path);

        $this->assertTrue(strpos($contents, "pmssBuildCommand('dpkg'") === false, 'FileBot installer should delegate dpkg invocation to remoteBinary helper');
        $this->assertTrue(strpos($contents, "runStep(\"Downloading") === false, 'FileBot installer should delegate download step to remoteBinary helper');
    }

    public function testRemoteBinaryDefinesPinnedDebInstaller(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/remoteBinary.php';
        $contents = $this->readFile($path);

        $this->assertStringContainsString('function pmssInstallPinnedRemoteDebPackage', $contents);
        $this->assertStringContainsString('function pmssDownloadPinnedRemoteTempFile', $contents);
        $this->assertStringContainsString("pmssBuildCommand('dpkg'", $contents);
        $this->assertStringContainsString('checksum mismatch; refusing install', $contents);
    }

    public function testRemoteBinaryKeepsTempCleanupInlineWithFinally(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/remoteBinary.php';
        $contents = $this->readFile($path);

        $this->assertStringContainsString('pmssDownloadPinnedRemoteTempFile(', $contents);
        $this->assertStringContainsString("'pmss-remote-bin-'", $contents);
        $this->assertStringContainsString("'pmss-remote-deb-'", $contents);
        $this->assertStringContainsString('try {', $contents);
        $this->assertStringContainsString('} finally {', $contents);
        $this->assertTrue(strpos($contents, 'function pmssRemoteBinary') === false, 'remoteBinary.php should keep temp-file cleanup inline rather than adding a helper wrapper');
    }
}
