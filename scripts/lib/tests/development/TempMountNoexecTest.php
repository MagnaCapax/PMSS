<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/mountHardening.php';

class TempMountNoexecTest extends TestCase
{
    private $prevHardening;
    private $prevDryRun;

    public function setUp(): void
    {
        $this->prevHardening = getenv('PMSS_HARDEN_TMP_NOEXEC');
        $this->prevDryRun = getenv('PMSS_DRY_RUN');
    }

    public function tearDown(): void
    {
        $this->pmssRestoreEnv('PMSS_HARDEN_TMP_NOEXEC', $this->prevHardening);
        $this->pmssRestoreEnv('PMSS_DRY_RUN', $this->prevDryRun);
    }

    public function testSkipsWhenFlagDisabled(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-noexec-skip-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "tmpfs /tmp tmpfs defaults,nosuid,nodev 0 0\n";
        $original .= "tmpfs /dev/shm tmpfs defaults,nosuid,nodev 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "tmpfs /tmp tmpfs rw,nosuid,nodev 0 0\n");

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_NOEXEC');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempMountNoexec($logger, $fstab, $mounts);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->assertTrue($this->pmssMessagesContain($messages, 'disabled'), 'expected disabled log');

        $this->pmssRemoveTree($dir);
    }

    public function testSkipsWhenFlagExplicitlyFalse(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-noexec-false-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "tmpfs /tmp tmpfs defaults,nosuid,nodev 0 0\n";
        $original .= "tmpfs /dev/shm tmpfs defaults,nosuid,nodev 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "tmpfs /tmp tmpfs rw,nosuid,nodev 0 0\n");

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_NOEXEC=FALSE');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempMountNoexec($logger, $fstab, $mounts);

        $this->assertEquals($original, (string) file_get_contents($fstab));
        $this->assertTrue($this->pmssMessagesContain($messages, 'disabled via PMSS_HARDEN_TMP_NOEXEC'), 'expected explicit-false skip log');

        $this->pmssRemoveTree($dir);
    }

    public function testAddsNoexecOptionsToFstab(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-noexec-add-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "tmpfs /tmp tmpfs defaults,nosuid,nodev 0 0\n";
        $original .= "tmpfs /dev/shm tmpfs defaults,nosuid,nodev 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "tmpfs /tmp tmpfs rw,nosuid,nodev 0 0\n".
            "tmpfs /dev/shm tmpfs rw,nosuid,nodev 0 0\n");

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_NOEXEC=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempMountNoexec($logger, $fstab, $mounts);

        $updated = (string)file_get_contents($fstab);
        $this->assertStringContainsString('/tmp', $updated);
        $this->assertStringContainsString('/dev/shm', $updated);
        $this->assertStringContainsString('noexec', $updated);
        $this->assertTrue($this->pmssMessagesContain($messages, 'Updated /tmp mount options'), 'expected /tmp update log');

        $this->pmssRemoveTree($dir);
    }

    public function testRemovesConflictingOptions(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-noexec-conflict-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "tmpfs /tmp tmpfs defaults,exec,suid,dev 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\n");

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_NOEXEC=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempMountNoexec($logger, $fstab, $mounts);

        $options = $this->fstabOptionsForMount($fstab, '/tmp');
        $this->assertTrue(in_array('noexec', $options, true), 'expected noexec option');
        $this->assertTrue(in_array('nosuid', $options, true), 'expected nosuid option');
        $this->assertTrue(in_array('nodev', $options, true), 'expected nodev option');
        $this->assertTrue(!in_array('exec', $options, true), 'expected exec removed');
        $this->assertTrue(!in_array('suid', $options, true), 'expected suid removed');
        $this->assertTrue(!in_array('dev', $options, true), 'expected dev removed');

        $this->pmssRemoveTree($dir);
    }

    public function testAlreadyHardenedSkips(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-noexec-skip-hardened-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "tmpfs /tmp tmpfs defaults,noexec,nosuid,nodev 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "tmpfs /tmp tmpfs rw,noexec,nosuid,nodev 0 0\n");

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_NOEXEC=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempMountNoexec($logger, $fstab, $mounts);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->assertTrue($this->pmssMessagesContain($messages, 'already hardened'), 'expected already hardened log');

        $this->pmssRemoveTree($dir);
    }

    public function testMountMissingLeavesFstabUntouched(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-noexec-missing-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "UUID=abc /home ext4 defaults 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "tmpfs /dev/shm tmpfs rw,nosuid,nodev 0 0\n");

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_NOEXEC=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempMountNoexec($logger, $fstab, $mounts);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->assertTrue($this->pmssMessagesContain($messages, 'not found'), 'expected not found log');

        $this->pmssRemoveTree($dir);
    }

    public function testUnreadableFstabWarns(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-noexec-unreadable-', 0700);
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        file_put_contents($fstab, "tmpfs /tmp tmpfs defaults 0 0\n");
        file_put_contents($mounts, "tmpfs /tmp tmpfs rw,nosuid,nodev 0 0\n");
        chmod($fstab, 0000);

        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        putenv('PMSS_HARDEN_TMP_NOEXEC=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempMountNoexec($logger, $fstab, $mounts);

        $this->assertTrue($this->pmssMessagesContain($messages, 'not readable'), 'expected not readable log');
        chmod($fstab, 0600);

        $this->pmssRemoveTree($dir);
    }

    private function fstabOptionsForMount(string $fstab, string $mountPoint): array
    {
        $lines = file($fstab, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#') {
                continue;
            }
            $columns = preg_split('/\s+/', $trim);
            if (count($columns) < 4) {
                continue;
            }
            if ($columns[1] !== $mountPoint) {
                continue;
            }
            return array_values(array_filter(explode(',', $columns[3]), 'strlen'));
        }
        return [];
    }

}
