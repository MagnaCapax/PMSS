<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';

class UserConfigFilesystemTest extends TestCase
{
    public function testReadSerializedArrayFileReturnsArrayForValidPayload(): void
    {
        $path = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/resources.serialized';
        file_put_contents($path, serialize(['ramBlock' => 256, 'uploadSlots' => 4]));

        $this->assertEquals(['ramBlock' => 256, 'uploadSlots' => 4], \pmssReadSerializedArrayFile($path));
    }

    public function testReadSerializedArrayFileReturnsNullForMissingPath(): void
    {
        $this->assertSame(null, \pmssReadSerializedArrayFile('/nonexistent/pmss-user-config-fs'));
    }

    public function testReadSerializedArrayFileReturnsNullForMalformedPayload(): void
    {
        $path = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/bad.serialized';
        file_put_contents($path, 'not-a-serialized-array');

        $this->assertSame(null, \pmssReadSerializedArrayFile($path));
    }

    public function testReadSerializedArrayFileReturnsNullForSerializedObject(): void
    {
        $path = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/object.serialized';
        file_put_contents($path, serialize((object) ['ramBlock' => 256]));

        $this->assertSame(null, \pmssReadSerializedArrayFile($path));
    }

    public function testReadSerializedArrayFileRejectsSymlinkPayloads(): void
    {
        $root = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700);
        [, $link] = $this->pmssCreateSymlinkedFileOrSkip($root.'/target.serialized', $root.'/link.serialized', serialize(['ramBlock' => 128]), 0700);

        $this->assertSame(null, \pmssReadSerializedArrayFile($link));
    }

    public function testReadRtorrentResourcesReturnsEmptyArrayWhenMissing(): void
    {
        $this->assertSame([], \pmssReadOptionalSerializedArrayFile('/nonexistent/pmss-rtorrent-resources', 'rTorrent resource configuration'));
    }

    public function testReadRtorrentResourcesThrowsForInvalidPayload(): void
    {
        $path = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/invalid-resources';
        file_put_contents($path, serialize('nope'));

        try {
            \pmssReadOptionalSerializedArrayFile($path, 'rTorrent resource configuration');
            $this->fail('Expected invalid rTorrent resource payload to throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Invalid rTorrent resource configuration:', $exception->getMessage());
        }
    }

    public function testReadRequiredFileReturnsContents(): void
    {
        $path = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/defaults.conf';
        file_put_contents($path, "enabled = yes\n");

        $this->assertSame("enabled = yes\n", \pmssReadRequiredRegularFile($path, 'demo defaults'));
    }

    public function testReadRequiredFileThrowsWhenPathMissing(): void
    {
        try {
            \pmssReadRequiredRegularFile('/nonexistent/pmss-required-file', 'demo defaults');
            $this->fail('Expected missing required file to throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Missing demo defaults:', $exception->getMessage());
        }
    }

    public function testReadRequiredFileThrowsForSymlink(): void
    {
        $root = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700);
        [, $link] = $this->pmssCreateSymlinkedFileOrSkip($root.'/target.conf', $root.'/link.conf', "enabled = no\n", 0700);

        try {
            \pmssReadRequiredRegularFile($link, 'demo defaults');
            $this->fail('Expected symlinked required file to throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Missing demo defaults:', $exception->getMessage());
        }
    }

    public function testReadRegularFileContentsPreservesWhitespace(): void
    {
        $path = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/content.txt';
        file_put_contents($path, "  enabled = yes\n");

        $this->assertSame("  enabled = yes\n", \pmssReadRegularFileContents($path));
    }

    public function testFilesystemReadersRejectNulBytePathsFailSoft(): void
    {
        $root = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700);
        $config = $this->pmssWriteFile($root.'/content.txt', "enabled = yes\n");
        $serialized = $this->pmssWriteFile($root.'/resources.serialized', serialize(['ramBlock' => 256]));
        $passwd = $this->pmssWriteFile($root.'/passwd', "alice:x:1001:1001::/home/alice:/bin/bash\n");

        $this->assertTrue(\pmssFilesystemPathHasNulByte($config."\0suffix"));
        $this->assertSame(null, \pmssReadRegularFileContents($config."\0suffix"));
        $this->assertSame([], \pmssReadOptionalSerializedArrayFile($serialized."\0suffix", 'demo resources'));
        $this->assertSame(null, \pmssColonRecordFieldsLookup($passwd."\0suffix", 'alice', 7, false));
        $this->assertSame('fallback-host', \pmssHostnameRead('fallback-host', $config."\0suffix"));
    }

    public function testFilesystemDirectoryHelpersRejectNulBytePathsFailSoft(): void
    {
        $logs = [];
        $root = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700);

        $this->assertFalse(\pmssDirEnsureExists($root."\0suffix", 0755));
        $this->assertSame(null, \pmssPrivateTempDirRealpath($root."\0suffix", 'pmss-user-config-fs-', $this->pmssMakeArrayLogger($logs)));
        $this->assertTrue($this->pmssMessagesContain($logs, 'Refusing temporary directory cleanup for unsafe path'));
    }

    public function testRequiredFileRejectsNulBytePathBeforeFilesystemCall(): void
    {
        $path = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/defaults.conf', "enabled = yes\n");

        try {
            \pmssReadRequiredRegularFile($path."\0suffix", 'demo defaults');
            $this->fail('Expected NUL-byte required file path to throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Missing demo defaults:', $exception->getMessage());
        }
    }

    public function testUserConfigUsesSharedRuntimeFilesystemHelpers(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/util/userConfig.php', "pmssReadOptionalSerializedArrayFile('/etc/seedbox/config/system.rtorrent.resources', 'rTorrent resource configuration')");
        $this->pmssAssertRepoFileContainsString('scripts/util/userConfig.php', "pmssReadRequiredRegularFile('/etc/seedbox/config/template.qbittorrent.conf', 'qBittorrent template')");
        $this->pmssAssertRepoFileNotContainsString('scripts/util/userConfig.php', "unserialize((string) file_get_contents(\$resourceFile))");
    }
}
