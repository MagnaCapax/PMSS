<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/services/quota.php';

class QuotaFstabOptionsTest extends TestCase
{
    public function testNoChangeWhenQuotaOptionsPresent(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-quota-', 0700);
        $fstab = $dir.'/fstab';

        $original = "UUID=abc /home ext4 defaults,noatime,usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1 0 0\n";
        file_put_contents($fstab, $original);

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Quota options already present'), 'expected skip log');
    }

    public function testAddsQuotaOptionsAndCreatesBackup(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-quota-', 0700);
        $fstab = $dir.'/fstab';

        $original = "UUID=abc /home ext4 defaults,noatime 0 0\n";
        file_put_contents($fstab, $original);

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);

        $updated = (string)file_get_contents($fstab);
        $this->assertStringContainsString('usrjquota=aquota.user', $updated);
        $this->assertStringContainsString('grpjquota=aquota.group', $updated);
        $this->assertStringContainsString('jqfmt=vfsv1', $updated);
        $this->assertStringContainsString('defaults,noatime', $updated);

        $backups = glob($fstab.'.pmss-backup-*') ?: [];
        $this->assertEquals(1, count($backups), 'expected exactly one backup');
        $this->assertEquals($original, (string)file_get_contents($backups[0]));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Updated quota options'), 'expected update log');
    }

    public function testDefaultsOnlyLineDropsDefaultsToken(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-quota-', 0700);
        $fstab = $dir.'/fstab';

        file_put_contents($fstab, "UUID=abc /home ext4 defaults 0 0\n");

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);

        $updated = (string)file_get_contents($fstab);
        $this->assertStringContainsString('usrjquota=aquota.user', $updated);
        $this->assertStringContainsString('grpjquota=aquota.group', $updated);
        $this->assertStringContainsString('jqfmt=vfsv1', $updated);
        $this->assertTrue(strpos($updated, 'defaults,') === false, 'expected defaults token removed');
    }

    public function testMountPointMissingDoesNotTouchFile(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-quota-', 0700);
        $fstab = $dir.'/fstab';

        $original = "UUID=abc /srv ext4 defaults,noatime 0 0\n";
        file_put_contents($fstab, $original);

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->assertTrue($this->pmssMessagesContain($messages, 'not found'), 'expected not-found log');
    }

    public function testUnreadableFstabSkipsConfiguration(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-quota-', 0700);
        $fstab = $dir.'/fstab';

        file_put_contents($fstab, "UUID=abc /home ext4 defaults 0 0\n");
        chmod($fstab, 0000);

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);

        $this->assertTrue($this->pmssMessagesContain($messages, 'not readable'), 'expected not-readable log');
        chmod($fstab, 0600);
    }

    public function testWarnUnexpectedQuotaFilesNoUnexpectedEntries(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-quota-files-', 0700);
        file_put_contents($dir.'/aquota.user', 'x');
        file_put_contents($dir.'/aquota.group', 'x');

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        \pmssWarnUnexpectedQuotaFiles($dir, $logger);
        $this->assertEquals([], $messages);
    }

    public function testWarnUnexpectedQuotaFilesEscapesGarbageNames(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-quota-files-', 0700);

        $garbage = 'aquota.gro'.chr(3);
        if (@file_put_contents($dir.'/'.$garbage, 'x') === false) {
            throw new SkipTest('filesystem does not support control character filenames');
        }

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        \pmssWarnUnexpectedQuotaFiles($dir, $logger);
        $this->assertTrue(count($messages) === 1, 'expected exactly one warning');
        $this->assertStringContainsString('aquota.gro\\003', $messages[0]);
    }

    public function testRemoveStaleQuotaCheckFilesLogsNoStaleEntries(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-quota-clean-', 0700);

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        $removed = \pmssRemoveStaleQuotaCheckFiles($dir, $logger);

        $this->assertEquals(0, $removed);
        $this->assertTrue($this->pmssMessagesContain($messages, 'No stale files found'), 'expected no-stale log');
    }

    public function testRemoveStaleQuotaCheckFilesDeletesRegularFiles(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-quota-clean-', 0700);
        $stale = $dir.'/aquota.user.new';
        file_put_contents($stale, 'stale');

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        $removed = \pmssRemoveStaleQuotaCheckFiles($dir, $logger);

        $this->assertEquals(1, $removed);
        $this->assertFalse(file_exists($stale), 'expected stale file removed');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Removed stale file'), 'expected removal log');
    }

    public function testRemoveStaleQuotaCheckFilesSkipsMatchedDirectories(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-quota-clean-', 0700);
        $staleDir = $dir.'/aquota.user.new';
        mkdir($staleDir, 0700);

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        $removed = \pmssRemoveStaleQuotaCheckFiles($dir, $logger);

        $this->assertEquals(0, $removed);
        $this->assertTrue(is_dir($staleDir), 'expected matched directory to remain');
        $this->assertTrue($this->pmssMessagesContain($messages, 'skipped unsafe stale quota path'), 'expected unsafe-path log');
    }

    public function testRemoveStaleQuotaCheckFilesRejectsRelativeMountPoint(): void
    {
        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        $removed = \pmssRemoveStaleQuotaCheckFiles('relative/home', $logger);

        $this->assertEquals(0, $removed);
        $this->assertTrue($this->pmssMessagesContain($messages, 'refusing unsafe quota cleanup path'), 'expected unsafe mount log');
    }

    public function testRemoveStaleQuotaCheckFilesRejectsSymlinkMountPoint(): void
    {
        $target = $this->pmssMakeTempDir('pmss-quota-target-', 0700);
        $link = $this->pmssMakeTempPath('pmss-quota-link-');
        if (!@symlink($target, $link)) {
            throw new SkipTest('filesystem does not support symlinks');
        }

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        $removed = \pmssRemoveStaleQuotaCheckFiles($link, $logger);

        $this->assertEquals(0, $removed);
        $this->assertTrue($this->pmssMessagesContain($messages, 'refusing quota cleanup outside stable mount point'), 'expected symlink mount log');
    }

    public function testRemoveStaleQuotaCheckFilesEscapesRemovedPath(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-quota-clean-', 0700);
        $stale = $dir.'/aquota.gro'.chr(3).'new';
        if (@file_put_contents($stale, 'stale') === false) {
            throw new SkipTest('filesystem does not support control character filenames');
        }

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        $removed = \pmssRemoveStaleQuotaCheckFiles($dir, $logger);

        $this->assertEquals(1, $removed);
        $this->assertTrue(count($messages) === 1, 'expected one removal log');
        $this->assertStringContainsString('aquota.gro\\003new', $messages[0]);
    }

}
