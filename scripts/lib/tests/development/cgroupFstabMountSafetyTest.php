<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class CgroupFstabMountSafetyTest extends TestCase
{
    public function testCgroupV1FstabMountAddsManagedEntryWithBackup(): void
    {
        $fstab = $this->pmssMakeTempFile('pmss-cgroup-fstab-');
        $this->pmssWriteFile($fstab, "UUID=abc / ext4 defaults 0 0\n");
        $messages = [];

        $changed = \pmssCgroupV1FstabMountEnsure($fstab, $this->pmssMakeArrayLogger($messages));

        $this->assertSame(true, $changed);
        $this->assertStringContainsString(
            "cgroup\t/sys/fs/cgroup\tcgroup\tdefaults\t0\t0",
            (string) file_get_contents($fstab)
        );
        $this->assertTrue((glob($fstab.'.pmss-backup-*') ?: []) !== [], 'expected fstab backup');
        $this->pmssAssertMessagesContain($messages, 'Appended cgroup mount configuration', 'expected append log');
    }

    public function testCgroupV1FstabMountSkipsExistingEntry(): void
    {
        $original = "cgroup /sys/fs/cgroup cgroup defaults 0 0\n";
        $fstab = $this->pmssMakeTempFile('pmss-cgroup-fstab-existing-');
        $this->pmssWriteFile($fstab, $original);
        $messages = [];

        $changed = \pmssCgroupV1FstabMountEnsure($fstab, $this->pmssMakeArrayLogger($messages));

        $this->assertSame(false, $changed);
        $this->assertEquals($original, (string) file_get_contents($fstab));
        $this->assertSame([], glob($fstab.'.pmss-backup-*') ?: []);
        $this->pmssAssertMessagesContain($messages, 'already present', 'expected skip log');
    }

    public function testCgroupV1FstabMountRejectsSymlinkedFstab(): void
    {
        $target = $this->pmssMakeTempFile('pmss-cgroup-fstab-target-');
        $link = $this->pmssMakeTempPath('pmss-cgroup-fstab-link-');
        $this->pmssWriteFile($target, "UUID=abc / ext4 defaults 0 0\n");
        $this->pmssCreateSymlinkOrSkip($target, $link);
        $messages = [];

        $changed = \pmssCgroupV1FstabMountEnsure($link, $this->pmssMakeArrayLogger($messages));

        $this->assertSame(null, $changed);
        $this->assertEquals("UUID=abc / ext4 defaults 0 0\n", (string) file_get_contents($target));
        $this->pmssAssertMessagesContain($messages, 'not a regular file', 'expected symlink guard log');
    }
}
