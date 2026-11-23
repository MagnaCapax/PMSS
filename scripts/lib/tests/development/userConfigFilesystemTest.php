<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';

class UserConfigFilesystemTest extends TestCase
{
    public function testReadSerializedArrayFileReturnsArrayForValidPayload(): void
    {
        $path = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/resources.serialized';
        $this->pmssWriteSerializedFixture($path, ['ramBlock' => 256, 'uploadSlots' => 4]);

        $this->assertEquals(['ramBlock' => 256, 'uploadSlots' => 4], \pmssReadSerializedArrayFile($path));
    }

    public function testReadSerializedArrayFileReturnsNullForMissingPath(): void
    {
        $this->assertSame(null, \pmssReadSerializedArrayFile('/nonexistent/pmss-user-config-fs'));
    }

    public function testReadSerializedArrayFileReturnsNullForMalformedPayload(): void
    {
        $path = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/bad.serialized', 'not-a-serialized-array');

        $this->assertSame(null, \pmssReadSerializedArrayFile($path));
    }

    public function testReadSerializedArrayFileReturnsNullForSerializedObject(): void
    {
        $path = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/object.serialized';
        $this->pmssWriteSerializedFixture($path, (object) ['ramBlock' => 256]);

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
        $this->pmssWriteSerializedFixture($path, 'nope');

        $this->assertThrowsRuntime(static function () use ($path): void {
            \pmssReadOptionalSerializedArrayFile($path, 'rTorrent resource configuration');
        }, 'Invalid rTorrent resource configuration:');
    }

    public function testReadRequiredFileReturnsContents(): void
    {
        $path = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/defaults.conf', "enabled = yes\n");

        $this->assertSame("enabled = yes\n", \pmssReadRequiredRegularFile($path, 'demo defaults'));
    }

    public function testReadRequiredFileThrowsWhenPathMissing(): void
    {
        $this->assertThrowsRuntime(static function (): void {
            \pmssReadRequiredRegularFile('/nonexistent/pmss-required-file', 'demo defaults');
        }, 'Missing demo defaults:');
    }

    public function testReadRequiredFileThrowsForSymlink(): void
    {
        $root = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700);
        [, $link] = $this->pmssCreateSymlinkedFileOrSkip($root.'/target.conf', $root.'/link.conf', "enabled = no\n", 0700);

        $this->assertThrowsRuntime(static function () use ($link): void {
            \pmssReadRequiredRegularFile($link, 'demo defaults');
        }, 'Missing demo defaults:');
    }

    public function testReadRegularFileContentsPreservesWhitespace(): void
    {
        $path = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/content.txt', "  enabled = yes\n");

        $this->assertSame("  enabled = yes\n", \pmssReadRegularFileContents($path));
    }

    public function testFilesystemReadersRejectNulBytePathsFailSoft(): void
    {
        $root = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700);
        $config = $this->pmssWriteFile($root.'/content.txt', "enabled = yes\n");
        $serialized = $this->pmssWriteFile($root.'/resources.serialized', serialize(['ramBlock' => 256]));
        $passwd = $this->pmssWriteFile($root.'/passwd', "fixtur01:x:1001:1001::/home/fixtur01:/bin/bash\n");

        $this->assertTrue(\pmssFilesystemPathHasNulByte($config."\0suffix"));
        $this->assertSame(null, \pmssReadRegularFileContents($config."\0suffix"));
        $this->assertSame([], \pmssReadOptionalSerializedArrayFile($serialized."\0suffix", 'demo resources'));
        $this->assertSame(null, \pmssColonRecordFieldsLookup($passwd."\0suffix", 'fixtur01', 7, false));
        $this->assertSame('fallback-host', \pmssHostnameRead('fallback-host', $config."\0suffix"));
    }

    public function testFilesystemDirectoryHelpersRejectNulBytePathsFailSoft(): void
    {
        $logs = [];
        $root = $this->pmssMakeTempDir('pmss-user-config-fs-', 0700);

        $this->assertFalse(\pmssDirEnsureExists($root."\0suffix", 0755));
        $this->assertSame(null, \pmssPrivateTempDirRealpath($root."\0suffix", 'pmss-user-config-fs-', $this->pmssMakeArrayLogger($logs)));
        $this->pmssAssertMessagesContain($logs, 'Refusing temporary directory cleanup for unsafe path');
    }

    /** Octal permission bits of a path, e.g. '700'. */
    private function pmssModeOctal(string $path): string
    {
        clearstatcache(true, $path);
        return substr(sprintf('%o', fileperms($path)), -3);
    }

    public function testDirEnsureExistsCreatesMissingDirectoryAndParentsAtRequestedMode(): void
    {
        $root = $this->pmssMakeTempDir('pmss-dir-ensure-', 0700);
        $oldUmask = umask(0022);
        try {
            $this->assertTrue(\pmssDirEnsureExists($root.'/parent/child', 0700));
        } finally {
            umask($oldUmask);
        }

        $this->assertSame('700', $this->pmssModeOctal($root.'/parent/child'));
        $this->assertSame('700', $this->pmssModeOctal($root.'/parent'));
    }

    public function testDirEnsureExistsNeverWidensAnExistingDirectory(): void
    {
        // The hourly sweep owns the runtime trees at 0700; a 0755 request must not reopen one.
        $root = $this->pmssMakeTempDir('pmss-dir-ensure-', 0700);
        $existing = $root.'/runtime';
        $this->assertTrue(mkdir($existing, 0700));
        chmod($existing, 0700);

        $this->assertTrue(\pmssDirEnsureExists($existing, 0755));
        $this->assertSame('700', $this->pmssModeOctal($existing));
    }

    public function testDirEnsureExistsNeverTightensAnExistingDirectory(): void
    {
        $root = $this->pmssMakeTempDir('pmss-dir-ensure-', 0700);
        $existing = $root.'/shared';
        $this->assertTrue(mkdir($existing, 0755));
        chmod($existing, 0755);

        $this->assertTrue(\pmssDirEnsureExists($existing, 0700));
        $this->assertSame('755', $this->pmssModeOctal($existing));
    }

    public function testDirEnsureExistsRequestedModeIsFilteredByUmask(): void
    {
        $root = $this->pmssMakeTempDir('pmss-dir-ensure-', 0700);
        $oldUmask = umask(0077);
        try {
            $this->assertTrue(\pmssDirEnsureExists($root.'/narrowed', 0750));
        } finally {
            umask($oldUmask);
        }

        $this->assertSame('700', $this->pmssModeOctal($root.'/narrowed'));
    }

    public function testRuntimeCreationLiteralsAgreeWithTheSweepOwnerOfTheirPath(): void
    {
        // Paths on pmssCheckDirectoriesRequiredDirectories() are swept to 0700, so their creators ask for 0700.
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/trafficLimits.php', ["pmssDirEnsureExists('/var/run/pmss/trafficLimits', 0700)"]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/systemStatsLog.php', ['pmssDirEnsureExists($logDir, 0700);']);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/checkRtorrent.php', ['pmssDirEnsureExists($stateDir, 0700)']);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/network/fireqos.php', ['pmssDirEnsureExists(dirname($logPath), 0700)']);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/wireguard.php', ['pmssDirEnsureExists($configDir, 0700);']);
    }

    public function testRequiredFileRejectsNulBytePathBeforeFilesystemCall(): void
    {
        $path = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-user-config-fs-', 0700).'/defaults.conf', "enabled = yes\n");

        $this->assertThrowsRuntime(static function () use ($path): void {
            \pmssReadRequiredRegularFile($path."\0suffix", 'demo defaults');
        }, 'Missing demo defaults:');
    }

    public function testUserConfigUsesSharedRuntimeFilesystemHelpers(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/userConfig.php', [
            "pmssReadOptionalSerializedArrayFile('/etc/seedbox/config/system.rtorrent.resources', 'rTorrent resource configuration')",
            "pmssReadRequiredRegularFile('/etc/seedbox/config/template.qbittorrent.conf', 'qBittorrent template')",
        ], ["unserialize((string) file_get_contents(\$resourceFile))"]);
    }
}
