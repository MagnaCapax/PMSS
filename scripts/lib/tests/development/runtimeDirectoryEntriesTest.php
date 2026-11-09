<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';

class RuntimeDirectoryEntriesTest extends TestCase
{
    public function testMalformedPathsReturnScanFailure(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-directory-entries-');
        foreach (['', "\0", "\0".$directory, $directory."\0", $directory."\0/child"] as $path) {
            $this->assertSame(false, pmssDirectoryEntriesRead($path));
        }
    }

    public function testMissingAndRegularFilePathsReturnScanFailure(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-directory-entries-');
        $this->pmssWriteFile($directory.'/file', 'fixture');
        foreach ([$directory.'/missing', $directory.'/file', $directory.'/file/child'] as $path) {
            $this->assertSame(false, pmssDirectoryEntriesRead($path));
        }
    }

    public function testEmptyDirectoryReturnsEmptyArray(): void
    {
        $this->assertSame([], pmssDirectoryEntriesRead($this->pmssMakeTempDir('pmss-directory-entries-')));
    }

    public function testScanPreservesKeysOrderingAndLiteralNames(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-directory-entries-');
        $this->pmssWriteFiles($directory, array_fill_keys(['z', '.hidden', ' spaced ', "line\nbreak", 'A'], 'fixture'));
        mkdir($directory.'/child');
        symlink($directory.'/missing', $directory.'/broken-link');
        $expected = array_diff(scandir($directory), ['.', '..']);
        $this->assertSame($expected, pmssDirectoryEntriesRead($directory));
        $this->assertSame($expected, pmssDirectoryEntriesRead($directory.'/'));
    }

    public function testDirectorySymlinkAndWhitespacePathRemainSupported(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-directory-entries-');
        $this->pmssWriteFile($directory.'/ spaced /entry', 'fixture', 0777);
        symlink($directory.'/ spaced ', $directory.'/alias');
        $expected = array_diff(scandir($directory.'/ spaced '), ['.', '..']);
        $this->assertSame($expected, pmssDirectoryEntriesRead($directory.'/ spaced '));
        $this->assertSame($expected, pmssDirectoryEntriesRead($directory.'/alias'));
    }
}
