<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/mountHardening.php';

class TempTmpfsMountTest extends TestCase
{
    private $prevFlag;
    private $prevSize;
    private $prevDryRun;

    public function setUp(): void
    {
        $this->prevFlag = getenv('PMSS_HARDEN_TMP_TMPFS');
        $this->prevSize = getenv('PMSS_TMPFS_TMP_SIZE');
        $this->prevDryRun = getenv('PMSS_DRY_RUN');
    }

    public function tearDown(): void
    {
        $this->pmssRestoreEnv('PMSS_HARDEN_TMP_TMPFS', $this->prevFlag);
        $this->pmssRestoreEnv('PMSS_TMPFS_TMP_SIZE', $this->prevSize);
        $this->pmssRestoreEnv('PMSS_DRY_RUN', $this->prevDryRun);
    }

    public function testSkipsWhenFlagDisabled(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-tmpfs-skip-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "UUID=abc / ext4 defaults 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "");

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_TMPFS');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->assertTrue($this->pmssMessagesContain($messages, 'disabled'), 'expected disabled log');

        $this->pmssRemoveTree($dir);
    }

    public function testSkipsWhenFlagExplicitlyFalse(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-tmpfs-false-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "UUID=abc / ext4 defaults 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "");

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_TMPFS=FALSE');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $this->assertEquals($original, (string) file_get_contents($fstab));
        $this->assertTrue($this->pmssMessagesContain($messages, 'disabled via PMSS_HARDEN_TMP_TMPFS'), 'expected explicit-false skip log');

        $this->pmssRemoveTree($dir);
    }

    public function testAddsTmpfsEntryWhenMissing(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-tmpfs-add-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "UUID=abc / ext4 defaults 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "");

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_TMPFS=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $updated = (string)file_get_contents($fstab);
        $this->assertStringContainsString('tmpfs /tmp tmpfs defaults,noexec,nosuid,nodev,size=2G 0 0', $updated);
        $this->assertTrue($this->pmssMessagesContain($messages, 'Added /tmp tmpfs entry'), 'expected add log');

        $this->pmssRemoveTree($dir);
    }

    public function testSkipsWhenNonTmpfsEntryExists(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-tmpfs-nontmp-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "UUID=abc /tmp ext4 defaults 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "");

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_TMPFS=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->assertTrue($this->pmssMessagesContain($messages, 'non-tmpfs'), 'expected non-tmpfs log');

        $this->pmssRemoveTree($dir);
    }

    public function testUpdatesTmpfsEntryOptions(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-tmpfs-update-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "tmpfs /tmp tmpfs defaults,exec,suid,dev,size=1G 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\n");

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_TMPFS=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $options = $this->pmssFstabOptionsForMount($fstab, '/tmp');
        $this->assertTrue(in_array('noexec', $options, true), 'expected noexec option');
        $this->assertTrue(in_array('nosuid', $options, true), 'expected nosuid option');
        $this->assertTrue(in_array('nodev', $options, true), 'expected nodev option');
        $this->assertTrue(in_array('size=2G', $options, true), 'expected size=2G');
        $this->assertTrue(!in_array('exec', $options, true), 'expected exec removed');
        $this->assertTrue(!in_array('suid', $options, true), 'expected suid removed');
        $this->assertTrue(!in_array('dev', $options, true), 'expected dev removed');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Updated /tmp tmpfs options'), 'expected update log');

        $this->pmssRemoveTree($dir);
    }

    public function testSizeOverride(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-tmpfs-size-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "UUID=abc / ext4 defaults 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "");

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_TMPFS=1');
        putenv('PMSS_TMPFS_TMP_SIZE=512M');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $updated = (string)file_get_contents($fstab);
        $this->assertStringContainsString('size=512M', $updated);
        $this->assertTrue($this->pmssMessagesContain($messages, 'size=512M'), 'expected size override log');

        $this->pmssRemoveTree($dir);
    }

    public function testUnreadableMountsWarns(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-tmpfs-mounts-unreadable-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        file_put_contents($fstab, "tmpfs /tmp tmpfs defaults 0 0\n");
        file_put_contents($mounts, "tmpfs /tmp tmpfs rw 0 0\n");
        chmod($mounts, 0000);

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_TMPFS=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $this->assertTrue($this->pmssMessagesContain($messages, 'not readable'), 'expected not readable log');
        chmod($mounts, 0600);
        $this->pmssRemoveTree($dir);
    }

    public function testWriteFailureSkipsTmpfsRemountWhenFstabUpdateCannotPersist(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-tmpfs-write-fail-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        file_put_contents($fstab, "tmpfs /tmp tmpfs defaults,exec,suid,dev,size=1G 0 0\n");
        file_put_contents($mounts, "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\n");
        chmod($dir, 0500);

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        $this->pmssResetRuntimeProfile();
        putenv('PMSS_HARDEN_TMP_TMPFS=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $this->assertEquals([], $this->pmssProfileCommands());
        $this->assertTrue($this->pmssMessagesContain($messages, 'Failed writing updated '.$fstab), 'expected write failure log');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Skipping live mount hardening because '.$fstab.' could not be updated'), 'expected remount skip log');

        chmod($dir, 0700);
        $this->pmssRemoveTree($dir);
    }

    public function testDryRunProfilesTmpfsRemountCommand(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-tmpfs-profile-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        file_put_contents($fstab, "tmpfs /tmp tmpfs defaults,exec,suid,dev,size=1G 0 0\n");
        file_put_contents($mounts, "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\n");

        $this->pmssResetRuntimeProfile();
        putenv('PMSS_HARDEN_TMP_TMPFS=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount(null, $fstab, $mounts);

        $this->assertEquals([
            "mount '-o' 'remount,noexec,nosuid,nodev,size=2G' '/tmp'",
        ], $this->pmssProfileCommands());

        $this->pmssRemoveTree($dir);
    }

}
