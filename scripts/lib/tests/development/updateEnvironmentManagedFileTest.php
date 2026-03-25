<?php
require_once dirname(__DIR__).'/common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/environment.php';

use PMSS\Tests\TestCase;

class UpdateEnvironmentManagedFileTest extends TestCase
{
    public function testManagedPathAcceptsRegularFileTarget(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-write-');
        $path = $root.'/managed.conf';
        $messages = [];

        $this->assertTrue(\pmssUpdateEnvironmentManagedPathIsSafe($path, 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertEquals([], $messages);
    }

    public function testManagedPathRejectsSymlinkedParentDirectory(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-link-');
        $realDir = $root.'/real';
        $this->pmssEnsureDir($realDir, 0755);
        $linkDir = $root.'/link';
        $this->pmssCreateSymlinkOrSkip($realDir, $linkDir);
        $messages = [];

        $this->assertFalse(\pmssUpdateEnvironmentManagedPathIsSafe($linkDir.'/managed.conf', 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe test target directory'));
    }

    public function testManagedPathRejectsFileAsParent(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-parent-file-');
        $parentFile = $root.'/not-a-dir';
        file_put_contents($parentFile, 'x');
        $messages = [];

        $this->assertFalse(\pmssUpdateEnvironmentManagedPathIsSafe($parentFile.'/managed.conf', 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe test target directory'));
    }

    public function testManagedWriteCreatesRegularFile(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-write-ok-');
        $path = $root.'/managed.conf';
        $messages = [];

        $this->assertTrue(\pmssUpdateEnvironmentWriteManagedFile($path, "alpha\n", 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertEquals("alpha\n", file_get_contents($path));
        $this->assertEquals([], $messages);
    }

    public function testManagedWriteRejectsSymlinkTarget(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-target-link-');
        $target = $root.'/target.conf';
        file_put_contents($target, "old\n");
        $link = $root.'/managed.conf';
        $this->pmssCreateSymlinkOrSkip($target, $link);
        $messages = [];

        $this->assertFalse(\pmssUpdateEnvironmentWriteManagedFile($link, "new\n", 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertEquals("old\n", file_get_contents($target));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe test target target'));
    }
}
