<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigFilesystem.php';

class UserConfigFilesystemTest extends TestCase
{
    public function testReadSerializedArrayFileReturnsArrayForValidPayload(): void
    {
        $path = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/resources.serialized';
        file_put_contents($path, serialize(['ramBlock' => 256, 'uploadSlots' => 4]));

        $this->assertEquals(['ramBlock' => 256, 'uploadSlots' => 4], \pmssUserConfigReadSerializedArrayFile($path));
    }

    public function testReadSerializedArrayFileReturnsNullForMissingPath(): void
    {
        $this->assertSame(null, \pmssUserConfigReadSerializedArrayFile('/nonexistent/pmss-user-config-fs'));
    }

    public function testReadSerializedArrayFileReturnsNullForMalformedPayload(): void
    {
        $path = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/bad.serialized';
        file_put_contents($path, 'not-a-serialized-array');

        $this->assertSame(null, \pmssUserConfigReadSerializedArrayFile($path));
    }

    public function testReadSerializedArrayFileReturnsNullForSerializedObject(): void
    {
        $path = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/object.serialized';
        file_put_contents($path, serialize((object) ['ramBlock' => 256]));

        $this->assertSame(null, \pmssUserConfigReadSerializedArrayFile($path));
    }

    public function testReadSerializedArrayFileRejectsSymlinkPayloads(): void
    {
        $root = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700);
        $target = $root.'/target.serialized';
        file_put_contents($target, serialize(['ramBlock' => 128]));
        $link = $root.'/link.serialized';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $this->assertSame(null, \pmssUserConfigReadSerializedArrayFile($link));
    }

    public function testReadRtorrentResourcesReturnsEmptyArrayWhenMissing(): void
    {
        $this->assertSame([], \pmssUserConfigReadRtorrentResources('/nonexistent/pmss-rtorrent-resources'));
    }

    public function testReadRtorrentResourcesThrowsForInvalidPayload(): void
    {
        $path = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/invalid-resources';
        file_put_contents($path, serialize('nope'));

        try {
            \pmssUserConfigReadRtorrentResources($path);
            $this->fail('Expected invalid rTorrent resource payload to throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Invalid rTorrent resource configuration:', $exception->getMessage());
        }
    }

    public function testReadRequiredFileReturnsContents(): void
    {
        $path = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/defaults.conf';
        file_put_contents($path, "enabled = yes\n");

        $this->assertSame("enabled = yes\n", \pmssUserConfigReadRequiredFile($path, 'demo defaults'));
    }

    public function testReadRequiredFileThrowsWhenPathMissing(): void
    {
        try {
            \pmssUserConfigReadRequiredFile('/nonexistent/pmss-required-file', 'demo defaults');
            $this->fail('Expected missing required file to throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Missing demo defaults:', $exception->getMessage());
        }
    }

    public function testReadRequiredFileThrowsForSymlink(): void
    {
        $root = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700);
        $target = $root.'/target.conf';
        file_put_contents($target, "enabled = no\n");
        $link = $root.'/link.conf';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        try {
            \pmssUserConfigReadRequiredFile($link, 'demo defaults');
            $this->fail('Expected symlinked required file to throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Missing demo defaults:', $exception->getMessage());
        }
    }

    public function testUserConfigUsesFilesystemSafetyHelpers(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/util/userConfig.php', "require_once __DIR__.'/../lib/user/userConfigFilesystem.php';");
        $this->pmssAssertRepoFileContainsString('scripts/util/userConfig.php', "pmssUserConfigReadRtorrentResources('/etc/seedbox/config/system.rtorrent.resources')");
        $this->pmssAssertRepoFileContainsString('scripts/util/userConfig.php', "pmssUserConfigReadRequiredFile('/etc/seedbox/config/template.qbittorrent.conf', 'qBittorrent template')");
        $this->pmssAssertRepoFileNotContainsString('scripts/util/userConfig.php', "unserialize((string) file_get_contents(\$resourceFile))");
    }
}
