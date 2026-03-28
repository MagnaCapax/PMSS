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

}
